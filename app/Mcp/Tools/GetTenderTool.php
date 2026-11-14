<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Models\DocumentExtraction;
use App\Models\DocumentLink;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_tender')]
#[Title('Detalle de licitación')]
#[Description('Ficha completa de una licitación: datos de HonduCompras, fechas críticas, contacto, oportunidad del pipeline, cotizaciones, documentos (pliego, captura) y análisis del pliego con su estado.')]
#[IsReadOnly]
#[IsIdempotent]
class GetTenderTool extends BaseTool
{
    protected ?array $record = ['tender_id', Tender::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'tender_id' => $schema->integer()->description('Id de la licitación.')->required(),
            ...$this->companySchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $tender = $this->find(Tender::query()->with(['institution:id,name', 'owner:id,name']), $request->get('tender_id'), 'la licitación');
        $this->authorize('view', $tender);

        $opportunity = $tender->opportunities()->oldest('id')->first(['id', 'title', 'stage', 'amount', 'probability']);
        $extractions = DocumentExtraction::query()->where('tender_id', $tender->id)->latest('id')->limit(10)->get();
        $documents = DocumentLink::query()
            ->where('linkable_type', Tender::class)->where('linkable_id', $tender->id)
            ->whereHas('document')
            ->with('document')
            ->latest('id')->limit(30)->get()
            ->map(fn (DocumentLink $link) => ['context' => $link->context, ...Present::document($link->document)])
            ->all();

        return $this->ok([
            'tender' => Present::tender($tender),
            'opportunity' => $opportunity ? [
                'id' => $opportunity->id,
                'stage' => $opportunity->stage,
                'stage_label' => $opportunity->stageLabel(),
                'amount' => (float) $opportunity->amount,
                'probability' => $opportunity->probability,
                ...$this->untrusted(['title' => $opportunity->title], 300),
            ] : null,
            'quotes' => Quote::query()->where('tender_id', $tender->id)->latest('id')->limit(20)
                ->get(['id', 'number', 'status', 'outcome', 'total'])
                ->map(fn (Quote $quote) => ['id' => $quote->id, 'number' => $quote->number, 'status' => $quote->status, 'outcome' => $quote->outcome, 'total' => (float) $quote->total])
                ->all(),
            'documents' => $documents,
            'bid_analyses' => $extractions->where('kind', DocumentExtraction::KIND_BID)->values()->map(fn ($e) => Present::analysis($e))->all(),
            'can' => [
                'update' => $user->can('update', $tender),
                'use_ai' => $user->can('use-ai') && $user->can('update', $tender),
            ],
        ]);
    }
}
