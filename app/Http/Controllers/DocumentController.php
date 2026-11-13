<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\McpAuthorization;
use App\Models\SigningAsset;
use App\Services\Documents\DocumentService;
use App\Services\Documents\SignatureService;
use App\Services\Notifications\CalendarSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $query = Document::query()->with('institution:id,name')->latest('id');

        if ($kind = $request->string('kind')->trim()->value()) {
            $query->where('kind', $kind);
        }

        if ($category = $request->string('category')->trim()->value()) {
            $query->where('category', $category);
        }

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->boolean('only_expired')) {
            $query->whereNotNull('expires_at')->whereDate('expires_at', '<', now()->toDateString());
        }

        return Inertia::render('Documents', [
            'records' => $query->paginate(20)->withQueryString()->through(fn (Document $document) => [
                'id' => (string) $document->id,
                'nombre' => $document->name,
                'tipo' => $document->kind,
                'tipoNombre' => $document->kindLabel(),
                'categoria' => $document->category,
                'titular' => $document->owner_name,
                'institucion' => $document->institution?->name,
                'emision' => $document->issued_at?->toDateString(),
                'vencimiento' => $document->expires_at?->toDateString(),
                'vencido' => $document->isExpired(),
                'estado' => $document->status,
                'version' => $document->version,
                'firmado' => $document->signed_at !== null,
                'sello' => $document->sealed_at !== null,
                'tamano' => $document->size,
            ]),
            'kinds' => Document::KINDS,
            'categories' => Document::CATEGORIES,
            'assets' => SigningAsset::query()->where('is_active', true)->orderBy('type')->get(['id', 'type', 'name', 'width', 'height']),
            'filters' => $request->only('search', 'kind', 'category'),
        ]);
    }

    public function store(Request $request, DocumentService $documents): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['nullable', 'in:'.implode(',', array_keys(Document::KINDS))],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'required_for' => ['nullable', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'status' => ['nullable', 'string', 'max:100'],
            'cotizacion_id' => ['nullable', 'integer', 'exists:quotes,id'],
            'file' => ['required', 'file', 'max:25600', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp'],
        ]);

        $data['company_id'] = $data['company_id'] ?? app(\App\Services\Tenancy\CompanyContext::class)->currentCompanyId();

        $document = $documents->create($data, $request->file('file'), $request->user());

        if (! empty($data['cotizacion_id'])) {
            $quote = \App\Models\Quote::query()->find($data['cotizacion_id']);

            abort_unless($quote && $quote->company_id === $document->company_id, 422, 'La cotización pertenece a otra empresa.');

            $documents->link($document, $quote, null, 'cotizacion');
        }

        app(CalendarSyncService::class)->syncForDocument($document);

        return back()->with('success', 'Documento cargado correctamente.');
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $document->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'kind' => ['nullable', 'in:'.implode(',', array_keys(Document::KINDS))],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'required_for' => ['nullable', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'status' => ['required', 'string', 'max:100'],
        ]));

        app(CalendarSyncService::class)->syncForDocument($document);

        return back()->with('success', 'Documento actualizado.');
    }

    /**
     * Descarga del archivo vigente.
     */
    public function download(Document $document)
    {
        $this->authorize('download', $document);

        abort_unless($document->path && Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download($document->path, $document->original_filename ?? $document->name);
    }

    /**
     * Vista en linea del documento, para el visor de la modal.
     */
    public function preview(Document $document)
    {
        $this->authorize('view', $document);

        abort_unless($document->path && Storage::disk($document->disk)->exists($document->path), 404);

        $disk = Storage::disk($document->disk);

        return response($disk->get($document->path), 200, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($document->original_filename ?? 'documento').'"',
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    /**
     * Nueva version del documento. El archivo anterior se conserva.
     */
    public function replace(Request $request, Document $document, DocumentService $documents): RedirectResponse
    {
        $this->authorize('update', $document);

        $request->validate(['file' => 'required|file|max:25600|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp']);

        $documents->addVersion($document, $request->file('file'), $request->user());

        app(CalendarSyncService::class)->syncForDocument($document);

        return back()->with('success', 'Nueva versión cargada. El archivo anterior se conserva.');
    }

    public function versions(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json([
            'data' => $document->versions()->get()->map(fn (DocumentVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'archivo' => $version->original_filename,
                'tamano' => $version->size,
                'hash' => $version->hash,
                'subido_en' => $version->created_at?->toDateTimeString(),
                'subido_por' => $version->uploader?->name,
            ])->all(),
        ]);
    }

    public function renew(Request $request, Document $document, DocumentService $documents): RedirectResponse
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'issued_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'file' => ['nullable', 'file', 'max:25600', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp'],
        ]);

        $document->update([
            'issued_at' => $data['issued_at'],
            'expires_at' => $data['expires_at'] ?? null,
            'status' => 'Vigente',
        ]);

        if ($request->hasFile('file')) {
            $documents->addVersion($document, $request->file('file'), $request->user());
        }

        app(CalendarSyncService::class)->syncForDocument($document);

        return back()->with('success', 'Documento renovado.');
    }

    public function destroy(Document $document, DocumentService $documents): RedirectResponse
    {
        $this->authorize('delete', $document);

        $documents->delete($document);

        return back()->with('success', 'Documento eliminado.');
    }

    /**
     * Emite una autorizacion de firma, vinculada al contenido exacto del
     * documento. Es el mecanismo verificable que exige el servidor MCP antes
     * de aplicar la firma.
     */
    public function issueAuthorization(Request $request, Document $document): JsonResponse
    {
        $this->authorize('sign', $document);

        $version = $document->versions()->orderByDesc('version')->first();

        abort_unless($version instanceof DocumentVersion, 422, 'El documento no tiene ninguna version cargada.');

        $authorization = McpAuthorization::create([
            'mcp_connection_id' => null,
            'user_id' => $request->user()->id,
            'company_id' => $document->company_id,
            'action' => 'firmar',
            'resource_type' => Document::class,
            'resource_id' => $document->id,
            'payload_hash' => $version->hash,
            'authorized_at' => now(),
            'expires_at' => now()->addMinutes(20),
        ]);

        return response()->json([
            'authorization_id' => $authorization->id,
            'expira_en' => $authorization->expires_at?->toDateTimeString(),
            'version' => $version->version,
            'hash' => $version->hash,
        ]);
    }

    /**
     * Prepara la firma: devuelve los datos necesarios para posicionar la
     * imagen y, si el documento ya fue autorizado, aplica la firma.
     */
    public function sign(Request $request, Document $document, SignatureService $signatures): JsonResponse
    {
        $this->authorize('sign', $document);

        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:signing_assets,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'x' => ['nullable', 'numeric', 'min:0'],
            'y' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:1'],
            'height' => ['nullable', 'numeric', 'min:1'],
            'rotation' => ['nullable', 'numeric'],
            'authorization_id' => ['nullable', 'integer', 'exists:mcp_authorizations,id'],
        ]);

        $asset = SigningAsset::query()->findOrFail($data['asset_id']);

        if ((int) $asset->company_id !== (int) $document->company_id) {
            abort(403, 'El activo de firma pertenece a otra empresa.');
        }

        $version = $document->versions()->orderByDesc('version')->first();

        abort_unless($version instanceof DocumentVersion, 422, 'El documento no tiene ninguna version cargada.');

        $authorization = null;

        if (! empty($data['authorization_id'])) {
            $authorization = McpAuthorization::query()->find($data['authorization_id']);

            abort_unless(
                $authorization && $authorization->isValid() && $authorization->payload_hash === $version->hash,
                422,
                'La autorizacion no es valida para la version actual del documento.',
            );
        }

        $derived = $signatures->apply($document, $version, $asset, [
            'page' => $data['page'] ?? 1,
            'x' => $data['x'] ?? 60,
            'y' => $data['y'] ?? 700,
            'width' => $data['width'] ?? 140,
            'height' => $data['height'] ?? 60,
            'rotation' => $data['rotation'] ?? 0,
        ], $request->user(), $authorization?->id);

        $authorization?->forceFill(['consumed_at' => now()])->save();

        return response()->json([
            'documento_id' => $document->id,
            'version' => $derived->version,
            'url' => route('documents.preview', $document),
        ]);
    }
}
