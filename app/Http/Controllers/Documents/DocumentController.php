<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\RenewDocumentRequest;
use App\Http\Requests\Documents\ReplaceDocumentRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UpdateDocumentRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Quote;
use App\Rules\BelongsToActiveCompany;
use App\Services\Documents\DocumentPresenter;
use App\Services\Documents\DocumentService;
use App\Services\Documents\FileTypes;
use App\Services\System\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Directorio Institucional (documentos de la empresa) y acceso a los archivos de cualquier
 * documento: vista previa, descarga, versiones, reemplazo, renovación y eliminación.
 */
class DocumentController extends Controller
{
    private const PER_PAGE = [25, 50, 100];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'category' => (string) $request->query('category', ''),
            'status' => (string) $request->query('status', ''),
        ];
        $perPage = in_array((int) $request->query('per_page'), self::PER_PAGE, true) ? (int) $request->query('per_page') : self::PER_PAGE[0];

        $query = Document::query()->directory()
            ->select(['id', 'company_id', 'name', 'reference_number', 'description', 'kind', 'owner_name', 'category', 'version', 'issued_at', 'expires_at', 'status', 'signed_at', 'updated_at'])
            ->with('currentVersion.uploader:id,name');

        if ($filters['search'] !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['search']).'%';
            $query->where(fn ($inner) => $inner->where('name', 'like', $term)
                ->orWhere('reference_number', 'like', $term)
                ->orWhere('owner_name', 'like', $term)
                ->orWhereHas('versions', fn ($version) => $version->where('original_filename', 'like', $term)));
        }

        if (array_key_exists($filters['category'], Document::CATEGORIES)) {
            $query->where('category', $filters['category']);
        }

        if (array_key_exists($filters['status'], Document::STATUSES)) {
            $query->withStatus($filters['status']);
        }

        $documents = $query
            ->orderByRaw('case when expires_at is null then 1 else 0 end')
            ->orderBy('expires_at')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Document $document) => DocumentPresenter::directoryRow($document));

        $user = $request->user();

        return Inertia::render('Directory/Index', [
            'documents' => $documents,
            'stats' => $this->stats(),
            'categories' => collect(Document::CATEGORIES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'filters' => $filters,
            'limits' => FileTypes::limits() + ['accept' => FileTypes::acceptAttribute(), 'types' => FileTypes::label()],
            'can' => [
                'create' => $user->can('create', Document::class),
                'update' => $user->hasPermission(Permissions::UPDATE),
                'delete' => $user->hasPermission(Permissions::DELETE),
            ],
        ]);
    }

    /** `/documentos` es el nombre antiguo del directorio. */
    public function legacyIndex(Request $request): RedirectResponse
    {
        return redirect()->route('directory.index', $request->query());
    }

    public function store(StoreDocumentRequest $request, DocumentService $documents): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validated();
        $data['kind'] = $data['kind'] ?? 'empresa';

        // La entidad a enlazar se resuelve ANTES de crear el documento.
        $quote = ! empty($data['cotizacion_id']) ? Quote::query()->findOrFail($data['cotizacion_id']) : null;

        if ($quote !== null) {
            $this->authorize('view', $quote);
        }

        $document = $documents->create(Arr::except($data, ['cotizacion_id']), $request->file('file'), $request->user(), $quote, ['context' => $quote ? 'cotizacion' : null]);

        return back()->with('success', "«{$document->name}» se subió correctamente.");
    }

    public function update(UpdateDocumentRequest $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $data = $request->validated();
        $before = Arr::only($document->only(array_keys($data)), array_keys($data));
        $document->fill($data)->save();

        app(AuditLogger::class)->record('documento.editado', $document, [
            'antes' => array_map(fn ($value) => $value instanceof Carbon ? $value->toDateString() : $value, $before),
            'despues' => $data,
        ]);

        return back()->with('success', 'Datos del documento actualizados.');
    }

    /** Nueva versión del archivo; la anterior se conserva en el historial. */
    public function replace(ReplaceDocumentRequest $request, Document $document, DocumentService $documents): RedirectResponse
    {
        $this->authorize('update', $document);

        $version = $documents->addVersion($document, $request->file('file'), $request->user(), ['note' => 'Reemplazo']);

        return back()->with('success', "Se cargó la versión {$version->version}. La anterior queda en el historial.");
    }

    public function renew(RenewDocumentRequest $request, Document $document, DocumentService $documents): RedirectResponse
    {
        $this->authorize('update', $document);

        $data = $request->validated();
        $documents->renew($document, $request->user(), $data['issued_at'], $data['expires_at'] ?? null, $request->file('file'));

        return back()->with('success', 'Documento renovado.');
    }

    public function destroy(Document $document, DocumentService $documents): RedirectResponse
    {
        $this->authorize('delete', $document);

        $documents->delete($document);

        return back()->with('success', "«{$document->name}» se eliminó con todas sus versiones.");
    }

    public function versions(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json([
            'data' => $document->versions()->with(['uploader:id,name', 'signer:id,name'])->get()
                ->map(fn (DocumentVersion $version) => DocumentPresenter::version($version, $document))
                ->all(),
        ]);
    }

    /** Descarga del archivo vigente. */
    public function download(Document $document, AuditLogger $audit): SymfonyResponse
    {
        $this->authorize('download', $document);

        $response = $this->fileResponse($document->disk, $document->path, $document->original_filename ?? $document->name, $document->mime_type, HeaderUtils::DISPOSITION_ATTACHMENT);

        $audit->record('documento.descargado', $document, ['version' => $document->version]);

        return $response;
    }

    /** Vista en línea del archivo vigente (visor de las modales). */
    public function preview(Request $request, Document $document, AuditLogger $audit): SymfonyResponse
    {
        $this->authorize('view', $document);

        $response = $this->fileResponse($document->disk, $document->path, $document->original_filename ?? $document->name, $document->mime_type, HeaderUtils::DISPOSITION_INLINE);
        $this->auditPreview($request, $audit, $document, $document->version);

        return $response;
    }

    public function versionDownload(Document $document, DocumentVersion $version, AuditLogger $audit): SymfonyResponse
    {
        $this->authorize('download', $document);

        $response = $this->fileResponse($version->disk, $version->path, $version->original_filename ?? $document->name, $version->mime_type, HeaderUtils::DISPOSITION_ATTACHMENT);

        $audit->record('documento.descargado', $document, ['version' => $version->version]);

        return $response;
    }

    public function versionPreview(Request $request, Document $document, DocumentVersion $version, AuditLogger $audit): SymfonyResponse
    {
        $this->authorize('view', $document);

        $response = $this->fileResponse($version->disk, $version->path, $version->original_filename ?? $document->name, $version->mime_type, HeaderUtils::DISPOSITION_INLINE);
        $this->auditPreview($request, $audit, $document, $version->version);

        return $response;
    }

    /**
     * Vista previa auditada (B6). El visor de PDF puede pedir el archivo por partes (Range):
     * solo cuenta la primera petición de cada apertura.
     */
    private function auditPreview(Request $request, AuditLogger $audit, Document $document, mixed $version): void
    {
        $range = (string) $request->headers->get('Range', '');

        if ($range === '' || str_starts_with($range, 'bytes=0-')) {
            $audit->record('documento.vista_previa', $document, ['version' => $version]);
        }
    }

    /**
     * «Descargar para oferta»: ZIP con los documentos VIGENTES elegidos del directorio.
     * Los vencidos se omiten (la pantalla avisa antes de pedirlo).
     */
    public function package(Request $request, DocumentService $documents): SymfonyResponse
    {
        $this->authorize('viewAny', Document::class);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', new BelongsToActiveCompany(Document::class)],
        ], [
            'ids.required' => 'Elige al menos un documento.',
            'ids.*.integer' => 'Uno de los documentos elegidos no es válido.',
        ]);

        $selected = Document::query()->directory()->whereIn('id', $data['ids'])->orderBy('category')->orderBy('name')->get();
        $selected->each(fn (Document $document) => $this->authorize('download', $document));
        $valid = $selected->reject(fn (Document $document) => $document->isExpired());

        if ($valid->isEmpty()) {
            return back()->with('error', 'Ninguno de los documentos elegidos está vigente. Renuévalos antes de armar la oferta.');
        }

        $path = $documents->zip($valid, $request->user());
        $name = 'Documentos para oferta '.now()->format('Y-m-d').'.zip';

        return response()->download($path, $name, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Documents-Excluded' => (string) ($selected->count() - $valid->count()),
        ])->deleteFileAfterSend();
    }

    /**
     * Archivo servido por streaming (sin cargarlo en memoria), con soporte de `Range` en disco local.
     */
    private function fileResponse(?string $disk, ?string $path, string $name, ?string $mime, string $disposition): SymfonyResponse
    {
        $disk = $disk ?: DocumentService::DISK;
        $storage = Storage::disk($disk);

        abort_unless($path && $storage->exists($path), 404, 'El archivo ya no está disponible en el servidor.');

        $name = str_replace(['/', '\\', '"'], '-', $name);
        $headers = [
            'Content-Type' => $mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ];

        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            $response = new BinaryFileResponse($storage->path($path), 200, $headers, false);
            $response->setContentDisposition($disposition, $name, Str::ascii($name));

            return $response;
        }

        return $storage->response($path, $name, $headers, $disposition);
    }

    /**
     * @return array{total: int, valid: int, expiring: int, expired: int, noExpiry: int}
     */
    private function stats(): array
    {
        $today = Carbon::today()->toDateString();
        $limit = Carbon::today()->addDays(Document::EXPIRING_DAYS)->toDateString();

        $row = Document::query()->directory()->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when expires_at < ? then 1 else 0 end) as expired', [$today])
            ->selectRaw('sum(case when expires_at >= ? and expires_at <= ? then 1 else 0 end) as expiring', [$today, $limit])
            ->selectRaw('sum(case when expires_at > ? then 1 else 0 end) as valid', [$limit])
            ->selectRaw('sum(case when expires_at is null then 1 else 0 end) as no_expiry')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'valid' => (int) ($row->valid ?? 0),
            'expiring' => (int) ($row->expiring ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'noExpiry' => (int) ($row->no_expiry ?? 0),
        ];
    }
}
