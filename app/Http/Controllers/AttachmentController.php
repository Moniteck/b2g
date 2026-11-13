<?php

namespace App\Http\Controllers;

use App\Http\Requests\Documents\StoreAttachmentRequest;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Services\Documents\Attachables;
use App\Services\Documents\DocumentPresenter;
use App\Services\Documents\DocumentService;
use App\Services\Documents\FileTypes;
use App\Services\Documents\UploadInspector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Adjuntos de cualquier entidad (`quote`, `tender`, `opportunity`, `contract`, `client`; ver
 * `Attachables`). Responde JSON; las subidas hechas con Inertia (`useUpload`) vuelven atrás con
 * un toast, y sus errores llegan bajo `file` o `files.N`.
 *
 *   GET    /adjuntos/{tipo}/{id}              lista (policy `view` de la entidad)
 *   POST   /adjuntos/{tipo}/{id}              sube uno o varios (policy `update` o `manageAttachments`)
 *   DELETE /adjuntos/{tipo}/{id}/{document}   quita uno (ídem)
 */
class AttachmentController extends Controller
{
    public function index(Request $request, string $type, int $id): JsonResponse
    {
        $entity = Attachables::find($type, $id);
        $this->authorize('view', $entity);

        return response()->json([
            'data' => $this->attachments($entity),
            'can' => ['edit' => $request->user()->can(Attachables::editAbility($entity), $entity)],
        ]);
    }

    /** Pantalla de prueba del panel (solo local y pruebas). */
    public function preview(Request $request, string $type, int $id): Response
    {
        $entity = Attachables::find($type, $id);
        $this->authorize('view', $entity);

        return Inertia::render('Directory/AttachmentsPreview', [
            'entityType' => $type,
            'entityId' => $entity->getKey(),
            'canEdit' => $request->user()->can(Attachables::editAbility($entity), $entity),
        ]);
    }

    public function store(StoreAttachmentRequest $request, string $type, int $id, DocumentService $documents, UploadInspector $inspector): JsonResponse|RedirectResponse
    {
        $entity = Attachables::find($type, $id);
        $this->authorize(Attachables::editAbility($entity), $entity);

        $files = $request->uploadedFiles();

        // Primero se revisan todos: si uno falla no se guarda ninguno y cada error va bajo su archivo.
        $errors = [];
        foreach ($files as $field => $file) {
            try {
                $inspector->inspect($file, $field);
            } catch (ValidationException $exception) {
                $errors += $exception->errors();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $created = DB::transaction(fn () => collect($files)->map(fn ($file, $field) => $documents->create([
            'company_id' => $entity->getAttribute('company_id'),
            'kind' => Attachables::kindOf($type),
            'category' => $request->validated('category'),
            'description' => $request->validated('description'),
        ], $file, $request->user(), $entity, [
            'field' => $field,
            'context' => $type,
            'category' => $request->validated('category'),
            'description' => $request->validated('description'),
            'allowed' => FileTypes::ALL,
        ]))->values());

        $message = $created->count() === 1 ? "Se adjuntó «{$created->first()->name}»." : "Se adjuntaron {$created->count()} archivos.";

        if ($request->header('X-Inertia')) {
            return back()->with('success', $message);
        }

        return response()->json([
            'message' => $message,
            'data' => $this->attachments($entity),
        ], 201);
    }

    public function destroy(Request $request, string $type, int $id, int $document, DocumentService $documents): JsonResponse|RedirectResponse
    {
        $entity = Attachables::find($type, $id);
        $this->authorize(Attachables::editAbility($entity), $entity);

        $link = DocumentLink::query()
            ->where('linkable_type', $entity::class)
            ->where('linkable_id', $entity->getKey())
            ->where('document_id', $document)
            ->whereHas('document')
            ->firstOrFail();

        $model = Document::query()->findOrFail($link->document_id);
        $deleted = $documents->detach($model, $entity, $request->user());
        $message = "Se quitó «{$model->name}».";

        if ($request->header('X-Inertia')) {
            return back()->with('success', $message);
        }

        return response()->json(['message' => $message, 'deleted' => $deleted]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attachments(Model $entity): array
    {
        return DocumentLink::query()
            ->where('linkable_type', $entity::class)
            ->where('linkable_id', $entity->getKey())
            ->whereHas('document')
            ->with([
                'creator:id,name',
                'document' => fn ($query) => $query->select(['id', 'company_id', 'name', 'description', 'category', 'version', 'original_filename', 'mime_type', 'size', 'uploaded_by', 'created_at']),
                'document.uploader:id,name',
                'document.currentVersion.signer:id,name',
            ])
            ->latest('id')
            ->get()
            ->map(fn (DocumentLink $link) => DocumentPresenter::attachment($link))
            ->all();
    }
}
