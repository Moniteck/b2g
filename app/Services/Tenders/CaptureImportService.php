<?php

namespace App\Services\Tenders;

use App\Jobs\ReadTenderCapture;
use App\Models\DocumentExtraction;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Tender;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentService;
use App\Services\Documents\FileTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Importación de la captura de la ficha de HonduCompras.
 *
 * 1. read():    revisa las dimensiones de la imagen, la guarda en un área temporal privada y
 *               encola su lectura (ReadTenderCapture → process(): OCR y análisis). No crea nada en
 *               la base de datos: devuelve un token de revisión cuyo estado se consulta hasta que
 *               el borrador está listo (la pantalla de revisión sondea `tenders.import.status`).
 * 2. review():  datos para la pantalla de revisión (campos, instituciones parecidas, duplicado).
 * 3. confirm(): con los datos corregidos por la persona, crea o actualiza la licitación,
 *               crea la oportunidad si se pidió y guarda la captura como documento de la licitación.
 *
 * El token solo vale para el usuario y la empresa que subieron la imagen, por 24 horas.
 */
class CaptureImportService
{
    private const DISK = 'local';

    private const DIRECTORY = 'tender-imports';

    private const TTL_HOURS = 24;

    /** Megapíxeles y lado máximos de una captura (hallazgo A1: bombas de píxeles antes del OCR). */
    public const MAX_PIXELS = 25_000_000;

    public const MAX_SIDE = 10_000;

    /** Estados de la lectura en cola. */
    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public function __construct(
        protected OcrReader $ocr,
        protected HonduComprasCaptureParser $parser,
        protected InstitutionMatcher $institutions,
        protected DocumentService $documents,
        protected AuditLogger $audit,
    ) {}

    /**
     * Rechaza, antes de guardarla y de llegar a Tesseract, una imagen con dimensiones desmesuradas
     * (un PNG de pocos KB puede declarar 30 000 × 30 000 px y expandirse a cientos de MB).
     *
     * @throws ValidationException
     */
    public function ensureReasonableDimensions(UploadedFile $image, string $field = 'file'): void
    {
        $size = @getimagesize($image->getRealPath());

        if ($size === false || ($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1) {
            throw ValidationException::withMessages([$field => 'No se pudo leer la imagen. Sube una captura PNG, JPG o WEBP válida.']);
        }

        [$width, $height] = $size;

        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw ValidationException::withMessages([$field => "La imagen mide {$width} × {$height} px y el máximo es 10 000 px por lado y 25 megapíxeles. Recorta la captura a la ficha del expediente."]);
        }
    }

    public function read(UploadedFile $image, User $user, int $companyId): string
    {
        $this->ensureReasonableDimensions($image);
        $this->purgeExpired();

        $token = (string) Str::uuid();
        $extension = strtolower($image->guessExtension() ?: $image->getClientOriginalExtension() ?: 'png');
        $path = self::DIRECTORY."/{$token}.{$extension}";
        Storage::disk(self::DISK)->putFileAs(self::DIRECTORY, $image, "{$token}.{$extension}");

        $state = [
            'user_id' => $user->id,
            'company_id' => $companyId,
            'image' => $path,
            'original_name' => $image->getClientOriginalName() ?: "captura.{$extension}",
            'mime' => $image->getMimeType(),
            'created_at' => now()->toIso8601String(),
            'status' => self::QUEUED,
            'text' => null,
            'parsed' => null,
            'error' => null,
            'confirmed_tender_id' => null,
        ];

        $this->save($token, $state);
        ReadTenderCapture::dispatch($token);

        return $token;
    }

    /**
     * Lectura en cola (ReadTenderCapture): OCR y análisis de la ficha. Idempotente: una captura ya
     * leída no se vuelve a leer.
     */
    public function process(string $token): void
    {
        $state = $this->rawState($token);

        if ($state === null || ! self::isPending($state)) {
            return;
        }

        $state['status'] = self::PROCESSING;
        $this->save($token, $state);

        try {
            $state['text'] = $this->ocr->read(Storage::disk(self::DISK)->path($state['image']));
            $state['parsed'] = $this->parser->parse($state['text']);

            if (! $state['parsed']['useful']) {
                $state['error'] = 'No se reconocieron los datos de la ficha de HonduCompras en la imagen. Completa el formulario a mano.';
            }
        } catch (RuntimeException $exception) {
            $state['error'] = $exception->getMessage().' Completa el formulario a mano.';
        }

        $state['status'] = self::READY;
        $this->save($token, $state);

        $this->audit->record('licitacion.captura_leida', null, [
            'captura' => $token,
            'campos_leidos' => $state['parsed'] ? count(array_filter($state['parsed']['fields'], fn ($f) => $f['status'] !== HonduComprasCaptureParser::MISSING)) : 0,
            'error' => $state['error'],
        ], User::query()->find($state['user_id']), (int) $state['company_id']);
    }

    /** El trabajo murió (tiempo agotado, reinicio): la revisión se abre para completar a mano. */
    public function markFailed(string $token, string $message): void
    {
        $state = $this->rawState($token);

        if ($state === null || ! self::isPending($state)) {
            return;
        }

        $state['status'] = self::READY;
        $state['error'] = $message;
        $this->save($token, $state);
    }

    /** ¿La lectura sigue en cola o en proceso? Las capturas anteriores a la cola ya están listas. */
    public static function isPending(array $state): bool
    {
        return in_array($state['status'] ?? self::READY, [self::QUEUED, self::PROCESSING], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawState(string $token): ?array
    {
        if (! Str::isUuid($token) || ! Storage::disk(self::DISK)->exists($this->statePath($token))) {
            return null;
        }

        $state = json_decode((string) Storage::disk(self::DISK)->get($this->statePath($token)), true);

        return is_array($state) ? $state : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function state(string $token, User $user, int $companyId): array
    {
        if (! Str::isUuid($token) || ! Storage::disk(self::DISK)->exists($this->statePath($token))) {
            abort(404);
        }

        $state = json_decode((string) Storage::disk(self::DISK)->get($this->statePath($token)), true);

        abort_unless(is_array($state) && (int) $state['user_id'] === $user->id && (int) $state['company_id'] === $companyId, 404);

        return $state;
    }

    public function imagePath(array $state): ?string
    {
        return $state['image'] && Storage::disk(self::DISK)->exists($state['image']) ? Storage::disk(self::DISK)->path($state['image']) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function review(string $token, array $state): array
    {
        $fields = $state['parsed']['fields'] ?? [];
        $reference = $fields['reference']['value'] ?? null;
        $entity = $fields['entity']['value'] ?? null;
        $candidates = $this->institutions->candidates($entity);

        return [
            'token' => $token,
            'pending' => self::isPending($state),
            'statusUrl' => route('tenders.import.status', $token),
            'imageUrl' => route('tenders.import.image', $token),
            'imageName' => $state['original_name'],
            'error' => $state['error'],
            'fields' => $fields,
            'ocrText' => $state['text'],
            'institutionCandidates' => $candidates,
            'suggestedInstitutionId' => ($candidates[0]['score'] ?? 0) >= InstitutionMatcher::CLEAR_MATCH ? $candidates[0]['id'] : null,
            'duplicate' => $this->duplicateOf($reference),
            'confirmedTenderId' => $state['confirmed_tender_id'],
        ];
    }

    /**
     * Licitación de la empresa activa con el mismo expediente (ignorando guiones y espacios).
     *
     * @return array{id: int, reference: string, title: string, status: string}|null
     */
    public function duplicateOf(?string $reference): ?array
    {
        if (blank($reference)) {
            return null;
        }

        $compact = TextMatcher::compact($reference);
        $tender = Tender::query()->where('reference', $reference)->first(['id', 'reference', 'title', 'status'])
            ?? Tender::query()->get(['id', 'reference', 'title', 'status'])->first(fn (Tender $t) => TextMatcher::compact($t->reference) === $compact);

        return $tender?->only(['id', 'reference', 'title', 'status']);
    }

    /**
     * @param  array<string, mixed>  $data  datos validados por CaptureConfirmRequest
     * @return array{tender: Tender, created: bool, warning: ?string}
     */
    public function confirm(string $token, array $state, array $data, User $user, int $companyId): array
    {
        $lock = Cache::lock("tender-import:{$token}", 60);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['token' => 'Esta captura ya se está confirmando. Espera unos segundos.']);
        }

        try {
            // Doble clic o reenvío: la importación ya se confirmó.
            $state = $this->state($token, $user, $companyId);
            if (self::isPending($state)) {
                throw ValidationException::withMessages(['token' => 'La captura todavía se está leyendo. Espera unos segundos.']);
            }
            if ($state['confirmed_tender_id'] && ($done = Tender::query()->find($state['confirmed_tender_id']))) {
                return ['tender' => $done, 'created' => false, 'warning' => null];
            }

            [$tender, $created] = DB::transaction(fn () => $this->persist($data, $user, $companyId));

            $state['confirmed_tender_id'] = $tender->id;
            $this->save($token, $state);
        } finally {
            $lock->release();
        }

        $warning = $this->attachImage($tender, $state, $user);

        $this->audit->record($created ? 'licitacion.importada' : 'licitacion.actualizada_por_captura', $tender, [
            'expediente' => $tender->reference,
            'oportunidad' => (bool) ($data['create_opportunity'] ?? false),
        ], $user);

        return ['tender' => $tender, 'created' => $created, 'warning' => $warning];
    }

    /**
     * @return array{0: Tender, 1: bool}
     */
    private function persist(array $data, User $user, int $companyId): array
    {
        $institutionId = $data['institution_id'] ?? null;

        if (! $institutionId && filled($data['new_institution_name'] ?? null)) {
            $institutionId = Institution::query()->firstOrCreate(
                ['name' => trim($data['new_institution_name'])],
                ['acronym' => $data['new_institution_acronym'] ?? null, 'status' => 'Activa', 'source' => 'HonduCompras'],
            )->id;
        }

        $attributes = TenderAttributes::fromInput($data) + ['institution_id' => $institutionId];

        $existing = Tender::query()->where('reference', $attributes['reference'])->lockForUpdate()->first();

        if ($existing === null && ! empty($data['update_tender_id'])) {
            $existing = Tender::query()->whereKey($data['update_tender_id'])->lockForUpdate()->first();
        }

        if ($existing !== null) {
            if (empty($data['update_existing'])) {
                throw ValidationException::withMessages(['reference' => "Ya existe la licitación {$existing->reference}. Marca «Actualizar la existente» para completar sus datos con esta captura."]);
            }

            abort_unless($user->can('update', $existing), 403);

            // Solo se completan o corrigen los campos con valor: lo vacío no borra lo que ya había.
            $existing->fill(array_filter($attributes, fn ($value) => $value !== null && $value !== ''))->save();
            $tender = $existing;
            $created = false;
        } else {
            // La ficha no trae monto: sin valor queda en 0 (la columna no admite nulo).
            $tender = Tender::create([
                ...$attributes,
                'company_id' => $companyId,
                'owner_id' => $user->id,
                'status' => 'Activa',
                'estimated_amount' => $attributes['estimated_amount'] ?? 0,
            ]);
            $created = true;
        }

        if (! empty($data['create_opportunity']) && ! $tender->opportunities()->exists()) {
            Opportunity::create([
                'company_id' => $tender->company_id,
                'tender_id' => $tender->id,
                'institution_id' => $tender->institution_id,
                'owner_id' => $user->id,
                'title' => Str::limit($tender->title, 250, ''),
                'opportunity_type' => $tender->purchase_type,
                'amount' => $tender->estimated_amount ?? 0,
                'probability' => 10,
                'stage' => 'prospecto',
                'expected_close_date' => $tender->presentation_deadline?->toDateString(),
            ]);
        }

        return [$tender, $created];
    }

    /**
     * Guarda la imagen como documento de la licitación y deja registro de la lectura.
     * Si falla, la licitación ya quedó guardada: se devuelve un aviso en lugar de un error.
     */
    private function attachImage(Tender $tender, array $state, User $user): ?string
    {
        $path = $this->imagePath($state);

        if ($path === null) {
            return null;
        }

        try {
            $file = new UploadedFile($path, $state['original_name'], $state['mime'], null, true);
            $document = $this->documents->create([
                'name' => "Captura HonduCompras {$tender->reference}",
                'kind' => 'proceso',
                'category' => 'Captura HonduCompras',
                'company_id' => $tender->company_id,
                'institution_id' => $tender->institution_id,
            ], $file, $user, $tender, ['context' => 'captura', 'category' => 'Captura HonduCompras', 'allowed' => ['png', 'jpg', 'jpeg', 'webp']]);

            DocumentExtraction::create([
                'company_id' => $tender->company_id,
                'tender_id' => $tender->id,
                'document_version_id' => $document->versions()->max('id'),
                'kind' => DocumentExtraction::KIND_CAPTURE,
                'status' => DocumentExtraction::CONFIRMED,
                'method' => 'tesseract',
                'extracted' => $state['parsed']['fields'] ?? null,
                'pages' => [$state['text'] ?? ''],
                'reviewed_at' => now(),
                'reviewed_by' => $user->id,
                'finished_at' => now(),
            ]);

            Storage::disk(self::DISK)->delete($state['image']);

            return null;
        } catch (ValidationException $exception) {
            return 'La licitación se guardó, pero la captura no se pudo adjuntar: '.collect($exception->errors())->flatten()->first();
        } catch (Throwable $exception) {
            Log::warning('No se pudo adjuntar la captura a la licitación.', ['tender' => $tender->id, 'error' => $exception->getMessage()]);

            return 'La licitación se guardó, pero la captura no se pudo adjuntar como documento.';
        }
    }

    private function save(string $token, array $state): void
    {
        Storage::disk(self::DISK)->put($this->statePath($token), json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    private function statePath(string $token): string
    {
        return self::DIRECTORY."/{$token}.json";
    }

    /** Borra importaciones abandonadas (más de 24 horas). */
    private function purgeExpired(): void
    {
        $disk = Storage::disk(self::DISK);
        $limit = now()->subHours(self::TTL_HOURS)->getTimestamp();

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if ($disk->lastModified($file) < $limit) {
                $disk->delete($file);
            }
        }
    }

    /** Extensiones de imagen aceptadas para la captura. */
    public static function imageExtensions(): array
    {
        return array_values(array_intersect(FileTypes::ALL, ['png', 'jpg', 'jpeg', 'webp']));
    }
}
