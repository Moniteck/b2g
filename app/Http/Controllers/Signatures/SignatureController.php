<?php

namespace App\Http\Controllers\Signatures;

use App\Http\Controllers\Controller;
use App\Http\Requests\Signatures\SignRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignatureApplication;
use App\Models\User;
use App\Services\Documents\SignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Firmar documentos con la firma y el sello PERSONALES del usuario.
 *
 * Requiere `sign_documents` en la empresa del documento y permiso de verlo.
 * Responde JSON para `Pages/Signatures/Partials/SignDocumentModal.vue`.
 */
class SignatureController extends Controller
{
    /**
     * Firma un documento del sistema: crea una versión nueva firmada.
     */
    public function sign(SignRequest $request, SignatureService $signatures): JsonResponse
    {
        $document = Document::query()->findOrFail($request->integer('document_id'));

        $this->authorize('view', $document);
        $this->authorize('sign', $document);

        $application = $signatures->signDocument($document, $request->user(), $request->options());

        return $this->signed($application, 'Documento firmado. Se guardó como una versión nueva; el original se conserva.');
    }

    /**
     * Firma un PDF subido suelto; con `save_to_documents` lo guarda en la empresa activa.
     */
    public function signUpload(SignRequest $request, SignatureService $signatures): JsonResponse
    {
        abort_unless($request->user()->can('sign-documents'), 403, 'Tu rol no permite firmar documentos.');

        $save = $request->boolean('save_to_documents');

        if ($save) {
            $this->authorize('create', Document::class);
        }

        $application = $signatures->signUpload(
            $request->file('file'),
            $request->user(),
            $request->options(),
            $save ? ['name' => $request->validated('document_name') ?: null] : null,
        );

        return $this->signed($application, $save
            ? 'PDF firmado y guardado en Documentos. El original queda como versión anterior.'
            : 'PDF firmado. Descárgalo desde aquí o desde tu historial.');
    }

    /**
     * Documentos PDF de la empresa activa, para el buscador de «Firmar un documento».
     */
    public function documents(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Document::class);

        $term = trim((string) $request->query('q', ''));

        $documents = Document::query()
            ->where(fn ($query) => $query->where('mime_type', 'application/pdf')->orWhere('original_filename', 'like', '%.pdf'))
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('original_filename', 'like', "%{$term}%")))
            ->latest('updated_at')
            ->limit(20)
            ->get(['id', 'name', 'original_filename', 'version', 'signed_at', 'category', 'updated_at']);

        return response()->json(['data' => $documents->map(fn (Document $document) => [
            'id' => $document->id,
            'name' => $document->name,
            'description' => collect([
                $document->category,
                'versión '.$document->version,
                $document->signed_at ? 'firmado' : null,
            ])->filter()->implode(' · '),
            'previewUrl' => route('signatures.documents.preview', $document),
        ])->all()]);
    }

    /**
     * Vista del archivo vigente de un documento, para colocar la firma.
     */
    public function preview(Document $document): HttpResponse
    {
        $this->authorize('view', $document);

        $disk = Storage::disk($document->disk ?: 'local');
        abort_unless($document->path && $disk->exists($document->path), 404);

        return $this->stream($disk->path($document->path), $document->mime_type ?: 'application/pdf', $document->original_filename ?: 'documento.pdf', 'inline');
    }

    /**
     * PDF firmado de una firma aplicada (versión firmada o PDF suelto).
     */
    public function download(Request $request, SignatureApplication $application): HttpResponse
    {
        return $this->result($request, $application, 'attachment');
    }

    public function view(Request $request, SignatureApplication $application): HttpResponse
    {
        return $this->result($request, $application, 'inline');
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(SignatureApplication $application): array
    {
        $pages = (array) $application->pages;

        return [
            'id' => $application->id,
            'document_id' => $application->document_id,
            'document_name' => $application->document_name,
            'applied_at' => $application->applied_at?->toIso8601String(),
            'used_signature' => $application->used_signature,
            'used_seal' => $application->used_seal,
            'all_pages' => $application->all_pages,
            'pages' => $pages,
            'page_count' => $application->page_count,
            'pages_label' => $application->all_pages || count($pages) === $application->page_count && $application->page_count > 1
                ? 'Todas ('.$application->page_count.')'
                : (count($pages) === 1 ? 'Página '.$pages[0] : 'Páginas '.implode(', ', $pages)).' de '.$application->page_count,
            'loose' => $application->document_id === null,
            'available' => $application->document_version_id !== null || $application->result_path !== null,
            'downloadUrl' => route('signatures.applications.download', $application),
            'viewUrl' => route('signatures.applications.view', $application),
        ];
    }

    /**
     * Última posición que usó el usuario, como punto de partida de la modal.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lastPlacements(User $user): array
    {
        $placements = SignatureApplication::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->latest('applied_at')->latest('id')
            ->value('placements');

        $placements = is_string($placements) ? json_decode($placements, true) : $placements;

        return is_array($placements) ? array_values($placements) : [];
    }

    protected function signed(SignatureApplication $application, string $message): JsonResponse
    {
        $application->load('document:id,name,version,signed_at,signed_by,company_id');
        $document = $application->document;

        return response()->json([
            'message' => $message,
            'application' => self::present($application),
            'document' => $document === null ? null : [
                'id' => $document->id,
                'name' => $document->name,
                'version' => $document->version,
                'signed_at' => $document->signed_at?->toIso8601String(),
                'signed_by' => $document->signed_by,
                'previewUrl' => route('signatures.documents.preview', $document),
                'downloadUrl' => route('signatures.applications.download', $application),
            ],
        ], 201);
    }

    protected function result(Request $request, SignatureApplication $application, string $disposition): HttpResponse
    {
        $user = $request->user();
        $name = $application->document_name.'-firmado.pdf';

        if ($application->document_version_id !== null) {
            $version = DocumentVersion::query()->with('document')->find($application->document_version_id);
            abort_if($version === null || $version->document === null, 404);

            // Del documento puede descargarlo quien lo pueda ver; del historial, su firmante.
            abort_unless($user->can('view', $version->document), 403);
            $disk = Storage::disk($version->disk);
            abort_unless($disk->exists($version->path), 404);

            return $this->stream($disk->path($version->path), 'application/pdf', $version->original_filename ?: $name, $disposition);
        }

        abort_unless((int) $application->user_id === (int) $user->id, 403);
        abort_if($application->result_path === null, 404);

        $disk = Storage::disk($application->result_disk ?: 'local');
        abort_unless($disk->exists($application->result_path), 404);

        return $this->stream($disk->path($application->result_path), 'application/pdf', $name, $disposition);
    }

    protected function stream(string $path, string $mime, string $name, string $disposition): HttpResponse
    {
        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.addcslashes(str_replace(["\r", "\n"], '', $name), '"\\').'"',
            'Cache-Control' => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
