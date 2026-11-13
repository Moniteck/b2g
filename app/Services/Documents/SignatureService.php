<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignatureApplication;
use App\Models\SigningAsset;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use TCPDF;

/**
 * Aplica la firma y el sello de la empresa sobre un PDF.
 *
 * El documento original no se modifica: se genera una version nueva que
 * conserva la anterior y queda registrada con su hash, el activo utilizado,
 * la posicion y el responsable.
 */
class SignatureService
{
    public function __construct(
        protected DocumentService $documents,
        protected AuditLogger $audit,
    ) {}

    /**
     * @param  array{page?: int, x?: float, y?: float, width?: float, height?: float, rotation?: float}  $position
     */
    public function apply(
        Document $document,
        DocumentVersion $version,
        SigningAsset $asset,
        array $position,
        User $actor,
        ?int $authorizationId = null,
    ): DocumentVersion {
        if (! $asset->isUsable()) {
            throw new RuntimeException('El activo de firma o sello no esta activo.');
        }

        $sourcePath = Storage::disk($version->disk)->path($version->path);
        $assetPath = Storage::disk($asset->disk)->path($asset->path);

        if (! is_readable($sourcePath)) {
            throw new RuntimeException('El documento original no esta disponible en el almacenamiento.');
        }

        if (! is_readable($assetPath)) {
            throw new RuntimeException('El archivo de firma o sello no esta disponible.');
        }

        $page = max((int) ($position['page'] ?? 1), 1);
        $pdf = $this->build($sourcePath, $assetPath, $page, $position);

        $contents = $pdf->Output('doc.pdf', 'S');
        $name = pathinfo((string) $version->original_filename, PATHINFO_FILENAME).'-firmado.pdf';

        $derived = DB::transaction(function () use ($document, $version, $asset, $position, $actor, $contents, $name, $authorizationId, $page) {
            $newVersion = $this->documents->storeDerivedVersion($document, $contents, $name);

            SignatureApplication::create([
                'document_version_id' => $newVersion->id,
                'signing_asset_id' => $asset->id,
                'user_id' => $actor->id,
                'company_id' => $document->company_id,
                'page' => $page,
                'x' => $position['x'] ?? 0,
                'y' => $position['y'] ?? 0,
                'width' => $position['width'] ?? 0,
                'height' => $position['height'] ?? 0,
                'rotation' => $position['rotation'] ?? 0,
                'document_hash' => $version->hash,
                'authorization_id' => $authorizationId,
                'result_path' => $newVersion->path,
                'applied_at' => now(),
            ]);

            $document->forceFill([
                'signed_at' => now(),
                'signed_by' => $actor->id,
                'sealed_at' => $asset->type === 'sello' ? now() : $document->sealed_at,
            ])->save();

            return $newVersion;
        });

        $this->audit->record('documento.firmado', $document, [
            'activo' => $asset->name,
            'tipo' => $asset->type,
            'pagina' => $page,
            'version_resultado' => $derived->version,
        ]);

        return $derived;
    }

    /**
     * @param  array<string, mixed>  $position
     */
    protected function build(string $sourcePath, string $assetPath, int $page, array $position): TCPDF
    {
        $pdf = new Fpdi;

        $pageCount = $pdf->setSourceFile($sourcePath);

        if ($page > $pageCount) {
            throw new RuntimeException("El documento solo tiene {$pageCount} paginas.");
        }

        $tplIndex = $pdf->importPage($page, '/Template');

        $pdf->AddPage();
        $pdf->useTemplate($tplIndex);

        $width = (float) ($position['width'] ?? 140);
        $height = (float) ($position['height'] ?? 60);
        $x = (float) ($position['x'] ?? 60);
        $y = (float) ($position['y'] ?? 700);

        $pdf->Image($assetPath, $x, $y, $width, $height, 'PNG', 'D', false, 0, '', false, 0, (float) ($position['rotation'] ?? 0));

        return $pdf;
    }
}
