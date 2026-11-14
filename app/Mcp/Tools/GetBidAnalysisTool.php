<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Mcp\Support\ToolError;
use App\Models\DocumentExtraction;
use App\Models\Tender;
use App\Models\User;
use App\Services\Ai\BidDocumentAnalyzer;
use App\Services\Tenders\BidReviewService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_bid_analysis')]
#[Title('Consultar análisis del pliego')]
#[Description('Estado del análisis con IA del pliego de una licitación (queued, processing, review, confirmed, failed). Cuando está en «review» devuelve la propuesta: por cada dato (fechas, lugar, vigencia, plazo, contacto) su valor, la cita textual, la página, si la cita se verificó en el PDF (status verified, doubtful o unverified), el valor actual de la licitación y si conviene aplicarlo; además garantías, documentos exigidos, productos y criterios. Nada se aplica sin confirm_bid_analysis.')]
#[IsReadOnly]
#[IsIdempotent]
class GetBidAnalysisTool extends BaseTool
{
    protected ?array $record = ['tender_id', Tender::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'tender_id' => $schema->integer()->description('Id de la licitación.')->required(),
            'analysis_id' => $schema->integer()->description('Id del análisis (por defecto, el más reciente).'),
            ...$this->companySchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $tender = $this->find(Tender::class, $request->get('tender_id'), 'la licitación');
        $this->authorize('view', $tender);

        $extraction = DocumentExtraction::query()
            ->where('tender_id', $tender->id)
            ->where('kind', DocumentExtraction::KIND_BID)
            ->when($request->get('analysis_id') !== null, fn ($query) => $query->whereKey((int) $request->get('analysis_id')))
            ->latest('id')
            ->first() ?? throw new ToolError('La licitación no tiene un análisis de pliego con ese id. Sube el pliego con upload_bid_document.');

        $payload = ['tender_id' => $tender->id, 'reference' => $tender->reference, 'analysis' => Present::analysis($extraction)];

        if (in_array($extraction->status, [DocumentExtraction::REVIEW, DocumentExtraction::CONFIRMED], true)) {
            $payload['fields'] = array_map(fn (array $row) => [
                'key' => $row['key'],
                'label' => $row['label'],
                'type' => $row['type'],
                'found' => $row['found'],
                'evidence' => $row['status'],
                'page' => $row['page'],
                'conflict' => $row['conflict'],
                'suggest_apply' => $row['apply'],
                ...$this->untrusted([
                    'value' => $row['value'],
                    'quote' => $row['quote'],
                    'note' => $row['note'],
                    'alternatives' => $row['alternatives'],
                    'current' => $row['current'],
                ], 1500),
            ], app(BidReviewService::class)->proposal($extraction, $tender));
            $payload['lists'] = $this->untrusted($extraction->extracted['lists'] ?? array_fill_keys(array_keys(BidDocumentAnalyzer::LISTS), []), 1500);
            $payload['applied'] = $extraction->extracted['applied'] ?? [];
            $payload['next_step'] = $extraction->status === DocumentExtraction::REVIEW
                ? 'Revisa cada dato con la persona (sobre todo las fechas y los marcados unverified o con conflict) y aplica lo aprobado con confirm_bid_analysis.'
                : 'Este análisis ya se confirmó.';
        } elseif ($extraction->isPending()) {
            $payload['next_step'] = 'El análisis sigue en proceso. Vuelve a consultar en unos minutos.';
        }

        return $this->ok($payload);
    }
}
