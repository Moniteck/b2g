<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\GenerateDocumentRequest;
use App\Models\GeneratedDocument;
use App\Services\AuditLogger;
use App\Services\OpenAIService;
use App\Support\Permissions;
use App\Support\Tools\CompanyLetterhead;
use App\Support\Tools\DocumentTypes;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Generador de documentos empresariales con IA y el membrete de la empresa
 * activa. Cada autor ve su historial; el administrador ve el de la empresa.
 */
class DocumentGeneratorController extends Controller
{
    public const NOTICE = 'Borrador generado con IA. Revísalo antes de firmarlo y presentarlo.';

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', GeneratedDocument::class);

        $search = $request->string('search')->trim()->value();
        $type = array_key_exists((string) $request->query('type'), DocumentTypes::BUSINESS) ? $request->query('type') : null;
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $isAdmin = $request->user()->hasPermission(Permissions::DELETE);

        $records = GeneratedDocument::query()
            ->select(['id', 'company_id', 'user_id', 'type', 'title', 'recipient', 'content', 'created_at'])
            ->with('user:id,name')
            ->when(! $isAdmin, fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->where('title', 'like', "%{$search}%")->orWhere('recipient', 'like', "%{$search}%")))
            ->when($type, fn (Builder $query) => $query->where('type', $type))
            ->latest('id')
            ->paginate($perPage)->withQueryString()
            ->through(fn (GeneratedDocument $document) => $this->present($document));

        return Inertia::render('DocumentGenerator/Index', [
            'records' => $records,
            'filters' => ['search' => $search, 'type' => $type],
            'types' => DocumentTypes::businessOptions(),
            'company' => CompanyLetterhead::current(),
            'notice' => self::NOTICE,
            'can' => [
                'generate' => $request->user()->can('create', GeneratedDocument::class),
                'delete' => $isAdmin,
                'seesAll' => $isAdmin,
            ],
        ]);
    }

    public function store(GenerateDocumentRequest $request, OpenAIService $openAI, AuditLogger $audit): JsonResponse
    {
        $this->authorize('create', GeneratedDocument::class);

        $data = $request->validated();
        $company = CompanyLetterhead::current();

        try {
            $content = $openAI->businessDocument($data['type'], $company, $data);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage() ?: 'El servicio de IA no respondió. Inténtalo de nuevo en unos minutos.'], 422);
        }

        $document = GeneratedDocument::create([
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'title' => Str::limit(DocumentTypes::businessLabel($data['type']).' — '.$data['recipient'], 250, ''),
            'recipient' => $data['recipient'],
            'content' => $content,
            'metadata' => ['contact' => $data['contact'] ?? null, 'context' => $data['context'], 'company' => $company],
        ])->load('user:id,name');

        $audit->record('generated_document.created', $document, ['type' => $data['type']]);

        return response()->json(['document' => $this->present($document)], 201);
    }

    public function pdf(GeneratedDocument $generatedDocument)
    {
        $this->authorize('view', $generatedDocument);

        return Pdf::loadView('pdf.tools.text-document', [
            'title' => DocumentTypes::businessLabel($generatedDocument->type),
            'subtitle' => 'Para: '.$generatedDocument->recipient,
            'company' => $generatedDocument->metadata['company'] ?? CompanyLetterhead::current(),
            'generatedAt' => $generatedDocument->created_at ?? now(),
            'content' => $generatedDocument->content,
            'notice' => self::NOTICE,
        ])->download('documento-'.$generatedDocument->id.'-'.Str::slug($generatedDocument->type).'.pdf');
    }

    public function destroy(GeneratedDocument $generatedDocument, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $generatedDocument);

        $generatedDocument->delete();
        $audit->record('generated_document.deleted', $generatedDocument);

        return back()->with('success', 'Documento eliminado del historial.');
    }

    /** @return array<string, mixed> */
    private function present(GeneratedDocument $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type,
            'type_label' => DocumentTypes::businessLabel($document->type),
            'title' => $document->title,
            'recipient' => $document->recipient,
            'content' => $document->content,
            'author' => $document->user?->name,
            'created_at' => $document->created_at?->toIso8601String(),
            'pdf_url' => route('generated-documents.pdf', $document),
        ];
    }
}
