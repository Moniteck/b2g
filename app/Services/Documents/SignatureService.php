<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignatureApplication;
use App\Models\SigningAsset;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use App\Support\Binaries;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Tcpdf\Fpdi;
use Throwable;

/**
 * Estampa la firma y el sello PERSONALES del usuario sobre un PDF.
 *
 * - Importa todas las páginas con su tamaño y orientación originales (FPDI sobre TCPDF 6).
 * - Firma y sello en una sola operación: una sola versión nueva del documento.
 * - Las posiciones llegan en porcentaje de la página (origen arriba a la izquierda).
 * - Si FPDI no abre el PDF (xref comprimido, PDF 1.5+, escaneos), se normaliza con
 *   `pdftocairo -pdf` y se reintenta; si sigue sin abrir, error 422.
 * - El original nunca se sobrescribe y los temporales se borran siempre.
 *
 *   $service->signDocument($document, $user, ['use_signature' => true, 'use_seal' => true,
 *       'placements' => [['type' => 'firma', 'page' => 3, 'x' => 60, 'y' => 80, 'width' => 25, 'height' => 8]],
 *       'all_pages' => false, 'confirm' => false]);
 */
class SignatureService
{
    /** Segundos máximos de una operación de firma. */
    protected const TIME_LIMIT = 300;

    /** Memoria mínima durante el estampado. */
    protected const MEMORY_LIMIT_MB = 512;

    /** Binarios de Poppler con los que se reescribe un PDF que FPDI no abre, en orden. */
    protected const NORMALIZERS = ['pdfunite', 'pdftocairo'];

    public function __construct(
        protected DocumentService $documents,
        protected AuditLogger $audit,
    ) {}

    /**
     * Firma el documento del sistema y crea UNA versión nueva marcada como firmada.
     *
     * @param  array{use_signature?: bool, use_seal?: bool, placements: array<int, array<string, mixed>>, all_pages?: bool, confirm?: bool}  $options
     */
    public function signDocument(Document $document, User $user, array $options): SignatureApplication
    {
        $assets = $this->assetsFor($user, $options);

        return DB::transaction(function () use ($document, $user, $options, $assets) {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if (! ($options['confirm'] ?? false) && $this->hasSigned($locked, $user)) {
                throw new SignatureException('Ya firmaste este documento. Confirma para volver a firmarlo.', 'confirm', 409);
            }

            if ($locked->mime_type !== 'application/pdf' && ! str_ends_with(strtolower((string) $locked->original_filename), '.pdf')) {
                throw new SignatureException('Solo se pueden firmar documentos PDF.', 'document_id');
            }

            $disk = Storage::disk($locked->disk ?: 'local');

            if (! $locked->path || ! $disk->exists($locked->path)) {
                throw new SignatureException('El archivo del documento no está disponible.', 'document_id');
            }

            $source = DocumentVersion::query()
                ->where('document_id', $locked->id)
                ->orderByDesc('version')
                ->first();

            $result = $this->stamp($disk->path($locked->path), $assets, $options['placements'], (bool) ($options['all_pages'] ?? false));

            $name = Str::limit(pathinfo((string) ($locked->original_filename ?: $locked->name), PATHINFO_FILENAME), 120, '').'-firmado.pdf';

            try {
                $version = $this->documents->storeDerivedVersion($locked, $result['contents'], $name, $user);
            } catch (Throwable $exception) {
                report($exception);

                throw new SignatureException('No se pudo guardar la versión firmada. Vuelve a intentarlo.', 'document_id');
            }

            $locked->forceFill([
                'signed_at' => now(),
                'signed_by' => $user->id,
                'sealed_at' => isset($assets[SigningAsset::TYPE_SEAL]) ? now() : $locked->sealed_at,
            ])->save();

            $application = $this->record($user, $result, $options, $assets, [
                'company_id' => $locked->company_id,
                'document_id' => $locked->id,
                'source_version_id' => $source?->id,
                'document_version_id' => $version->id,
                'document_name' => $locked->name,
                'source_hash' => $source?->hash,
                'result_hash' => $version->hash,
            ]);

            $this->audit->record('documento.firmado', $locked, [
                'version_firmada' => $version->version,
                'firma' => isset($assets[SigningAsset::TYPE_SIGNATURE]),
                'sello' => isset($assets[SigningAsset::TYPE_SEAL]),
                'paginas' => $result['pages'],
                'normalizado' => $result['normalized'],
            ], $user);

            return $application;
        });
    }

    /**
     * Firma un PDF subido suelto. Con `$saveAs` (datos del documento) lo guarda en los
     * documentos de la empresa activa con el original como versión 1 y la firmada como 2;
     * sin él, el PDF firmado queda en disco privado solo para el firmante.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $saveAs
     */
    public function signUpload(UploadedFile $file, User $user, array $options, ?array $saveAs = null): SignatureApplication
    {
        $assets = $this->assetsFor($user, $options);

        // Se estampa primero: un PDF que no se puede firmar no llega a guardarse.
        $result = $this->stamp($file->getRealPath(), $assets, $options['placements'], (bool) ($options['all_pages'] ?? false));
        $originalName = $file->getClientOriginalName() ?: 'documento.pdf';
        $baseName = Str::limit(pathinfo($originalName, PATHINFO_FILENAME), 120, '');
        $companyId = app(CompanyContext::class)->currentCompanyId();

        if ($saveAs !== null) {
            return DB::transaction(function () use ($file, $user, $options, $assets, $result, $baseName, $saveAs, $companyId) {
                try {
                    $document = $this->documents->create([
                        'company_id' => $companyId,
                        'name' => $saveAs['name'] ?? $baseName,
                        'kind' => $saveAs['kind'] ?? 'proceso',
                        'category' => $saveAs['category'] ?? null,
                    ], $file, $user);
                    $source = $document->versions()->first();
                    $version = $this->documents->storeDerivedVersion($document, $result['contents'], $baseName.'-firmado.pdf', $user);
                } catch (ValidationException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);

                    throw new SignatureException('No se pudo guardar el documento firmado: '.$exception->getMessage());
                }

                $document->forceFill([
                    'signed_at' => now(),
                    'signed_by' => $user->id,
                    'sealed_at' => isset($assets[SigningAsset::TYPE_SEAL]) ? now() : null,
                ])->save();

                $application = $this->record($user, $result, $options, $assets, [
                    'company_id' => $document->company_id,
                    'document_id' => $document->id,
                    'source_version_id' => $source?->id,
                    'document_version_id' => $version->id,
                    'document_name' => $document->name,
                    'source_hash' => $source?->hash,
                    'result_hash' => $version->hash,
                ]);

                $this->audit->record('documento.firmado', $document, [
                    'version_firmada' => $version->version,
                    'paginas' => $result['pages'],
                    'origen' => 'subida',
                ], $user);

                return $application;
            });
        }

        $path = 'signatures/'.$user->id.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $result['contents']);

        try {
            $application = $this->record($user, $result, $options, $assets, [
                'company_id' => $companyId,
                'document_name' => $baseName,
                'source_hash' => hash_file('sha256', $file->getRealPath()),
                'result_hash' => hash('sha256', $result['contents']),
                'result_path' => $path,
                'result_disk' => 'local',
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        $this->audit->record('pdf.firmado', $application, [
            'archivo' => $originalName,
            'paginas' => $result['pages'],
        ], $user, $companyId);

        return $application;
    }

    public function hasSigned(Document $document, User $user): bool
    {
        return SignatureApplication::query()
            ->withoutGlobalScopes()
            ->where('document_id', $document->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Estampa las imágenes y devuelve el PDF resultante.
     *
     * @param  array<string, string>  $assets  tipo => ruta absoluta de la imagen
     * @param  array<int, array<string, mixed>>  $placements  [{ type, page, x, y, width, height }] en %
     * @return array{contents: string, page_count: int, pages: array<int, int>, normalized: bool}
     */
    public function stamp(string $sourcePath, array $assets, array $placements, bool $allPages = false): array
    {
        @set_time_limit(self::TIME_LIMIT);
        $this->raiseMemoryLimit();

        $this->assertLooksLikePdf($sourcePath);

        $placements = array_values(array_filter($placements, fn (array $placement) => isset($assets[$placement['type'] ?? ''])));

        if ($placements === []) {
            throw new SignatureException('Coloca la firma o el sello sobre el documento.', 'placements');
        }

        $temporary = null;
        $normalized = false;

        try {
            try {
                return $this->render($sourcePath, $assets, $placements, $allPages, false);
            } catch (SignatureException $exception) {
                throw $exception;
            } catch (Throwable) {
                // FPDI (versión libre) no lee xref comprimidos ni algunos PDF 1.5+: se normaliza.
                $normalized = true;
            }

            $lastError = null;

            foreach (self::NORMALIZERS as $binary) {
                if ($temporary !== null && is_file($temporary)) {
                    @unlink($temporary);
                }

                try {
                    $temporary = $this->normalize($sourcePath, $binary);
                } catch (SignatureException $exception) {
                    $lastError = $exception;

                    continue;
                }

                try {
                    return $this->render($temporary, $assets, $placements, $allPages, $normalized);
                } catch (SignatureException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            throw $lastError ?? new SignatureException('No se pudo abrir el PDF para firmarlo. Puede estar dañado o protegido.');
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param  array<string, string>  $assets
     * @param  array<int, array<string, mixed>>  $placements
     * @return array{contents: string, page_count: int, pages: array<int, int>, normalized: bool}
     */
    protected function render(string $path, array $assets, array $placements, bool $allPages, bool $normalized): array
    {
        $pdf = new Fpdi('P', 'pt', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->SetCreator('CRM B2G');

        $pageCount = $pdf->setSourceFile($path);

        if ($pageCount < 1) {
            // Se trata como ilegible: se intenta normalizar y, si no, error 422 sobre el archivo.
            throw new \RuntimeException('El PDF no tiene páginas legibles.');
        }

        foreach ($placements as $placement) {
            if (! $allPages && ((int) $placement['page'] < 1 || (int) $placement['page'] > $pageCount)) {
                throw new SignatureException("El documento solo tiene {$pageCount} páginas.", 'placements');
            }
        }

        $imageTypes = array_map(fn (string $image) => $this->imageType($image), $assets);
        $stamped = [];

        for ($number = 1; $number <= $pageCount; $number++) {
            $template = $pdf->importPage($number);
            $size = $pdf->getTemplateSize($template);
            $width = (float) $size['width'];
            $height = (float) $size['height'];

            $pdf->AddPage($width > $height ? 'L' : 'P', [$width, $height]);
            $pdf->useTemplate($template, 0, 0, $width, $height);

            foreach ($placements as $placement) {
                if (! $allPages && (int) $placement['page'] !== $number) {
                    continue;
                }

                $type = $placement['type'];
                $x = $this->percent($placement['x']) / 100 * $width;
                $y = $this->percent($placement['y']) / 100 * $height;
                $w = max($this->percent($placement['width']) / 100 * $width, 1);
                $h = max($this->percent($placement['height']) / 100 * $height, 1);

                $pdf->Image($assets[$type], $x, $y, $w, $h, $imageTypes[$type], '', '', false, 300, '', false, false, 0, false, false, false);
                $stamped[$number] = $number;
            }
        }

        return [
            'contents' => $pdf->Output('documento.pdf', 'S'),
            'page_count' => $pageCount,
            'pages' => array_values($stamped),
            'normalized' => $normalized,
        ];
    }

    /**
     * Reescribe el PDF con Poppler a un temporal que FPDI sí puede leer.
     *
     * `pdfunite` copia los objetos tal cual y escribe una tabla xref clásica (sin
     * object streams), que es lo que necesita el parser libre de FPDI; `pdftocairo`
     * queda de respaldo para PDF que `pdfunite` no puede leer (su salida con cairo
     * reciente vuelve a usar xref comprimido, así que no basta por sí solo).
     */
    protected function normalize(string $path, string $binary): string
    {
        $target = tempnam(sys_get_temp_dir(), 'firma-').'.pdf';
        $command = $binary === 'pdftocairo'
            ? [Binaries::poppler('pdftocairo'), '-pdf', $path, $target]
            : [Binaries::poppler('pdfunite'), $path, $target];

        try {
            $process = Process::timeout((int) config('tools.timeout', 120))->run($command);
        } catch (Throwable $exception) {
            @unlink($target);
            report($exception);

            throw new SignatureException('No se pudo preparar el PDF para firmarlo: falta la herramienta Poppler en el servidor.');
        } finally {
            @unlink(substr($target, 0, -4));
        }

        if (! $process->successful() || ! is_file($target) || filesize($target) === 0) {
            @unlink($target);
            $error = strtolower($process->errorOutput());

            throw new SignatureException(str_contains($error, 'password') || str_contains($error, 'encrypt')
                ? 'El PDF está protegido con contraseña. Quita la protección y vuelve a subirlo.'
                : 'No se pudo abrir el PDF para firmarlo. Puede estar dañado.');
        }

        return $target;
    }

    protected function assertLooksLikePdf(string $path): void
    {
        $handle = @fopen($path, 'rb');
        $head = $handle ? (string) fread($handle, 1024) : '';

        if ($handle) {
            fclose($handle);
        }

        if (! str_contains($head, '%PDF-')) {
            throw new SignatureException('El archivo no es un PDF válido.');
        }
    }

    /** Tipo real de la imagen (no se confía en la extensión). */
    protected function imageType(string $path): string
    {
        $info = @getimagesize($path);
        $type = $info === false ? null : $info[2];

        return match ($type) {
            IMAGETYPE_PNG => 'PNG',
            IMAGETYPE_JPEG => 'JPEG',
            default => throw new SignatureException('La imagen de firma o sello no es PNG ni JPG. Vuelve a subirla.', 'assets'),
        };
    }

    /**
     * Rutas de las imágenes del propio usuario que se van a aplicar.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    protected function assetsFor(User $user, array $options): array
    {
        $wanted = array_keys(array_filter([
            SigningAsset::TYPE_SIGNATURE => (bool) ($options['use_signature'] ?? false),
            SigningAsset::TYPE_SEAL => (bool) ($options['use_seal'] ?? false),
        ]));

        if ($wanted === []) {
            throw new SignatureException('Elige firma, sello o ambos.', 'use_signature');
        }

        $assets = SigningAsset::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('type', $wanted)
            ->get()
            ->keyBy('type');

        $paths = [];

        foreach ($wanted as $type) {
            $asset = $assets->get($type);
            $label = SigningAsset::TYPES[$type];

            if ($asset === null) {
                throw new SignatureException("Aún no has subido tu {$this->lower($label)}.", $type === 'firma' ? 'use_signature' : 'use_seal');
            }

            $disk = Storage::disk($asset->disk);

            if (! $disk->exists($asset->path)) {
                throw new SignatureException("El archivo de tu {$this->lower($label)} no está disponible. Vuelve a subirlo.", $type === 'firma' ? 'use_signature' : 'use_seal');
            }

            $paths[$type] = $disk->path($asset->path);
        }

        return $paths;
    }

    /**
     * @param  array{contents: string, page_count: int, pages: array<int, int>, normalized: bool}  $result
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $assets
     * @param  array<string, mixed>  $attributes
     */
    protected function record(User $user, array $result, array $options, array $assets, array $attributes): SignatureApplication
    {
        $placements = array_values(array_map(fn (array $placement) => [
            'type' => $placement['type'],
            'page' => (int) ($placement['page'] ?? 1),
            'x' => round($this->percent($placement['x']), 3),
            'y' => round($this->percent($placement['y']), 3),
            'width' => round($this->percent($placement['width']), 3),
            'height' => round($this->percent($placement['height']), 3),
        ], array_filter($options['placements'], fn (array $placement) => isset($assets[$placement['type'] ?? '']))));

        return SignatureApplication::create($attributes + [
            'user_id' => $user->id,
            'signer_name' => $user->name,
            'used_signature' => isset($assets[SigningAsset::TYPE_SIGNATURE]),
            'used_seal' => isset($assets[SigningAsset::TYPE_SEAL]),
            'all_pages' => (bool) ($options['all_pages'] ?? false),
            'pages' => $result['pages'],
            'placements' => $placements,
            'page_count' => $result['page_count'],
            'normalized' => $result['normalized'],
            'applied_at' => now(),
        ]);
    }

    protected function percent(mixed $value): float
    {
        return max(0.0, min(100.0, (float) $value));
    }

    protected function lower(string $label): string
    {
        return mb_strtolower($label);
    }

    protected function raiseMemoryLimit(): void
    {
        $current = ini_get('memory_limit');

        if ($current === '-1' || $current === false) {
            return;
        }

        $bytes = (int) $current * match (strtoupper(substr(trim($current), -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        if ($bytes < self::MEMORY_LIMIT_MB * 1024 ** 2) {
            @ini_set('memory_limit', self::MEMORY_LIMIT_MB.'M');
        }
    }
}
