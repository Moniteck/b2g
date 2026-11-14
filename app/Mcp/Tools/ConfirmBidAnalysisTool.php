<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Tenders\BidReviewRequest;
use App\Mcp\Support\Present;
use App\Mcp\Support\ToolError;
use App\Models\DocumentExtraction;
use App\Models\Tender;
use App\Models\User;
use App\Services\Tenders\BidReviewService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('confirm_bid_analysis')]
#[Title('Confirmar análisis del pliego')]
#[Description('Aplica a la licitación los datos del pliego que la persona aprobó y actualiza su calendario y recordatorios. `fields` es un objeto por clave del análisis (process_number, process_name, object, clarification_deadline, presentation_deadline, presentation_place, opening_at, offer_validity_days, delivery_term, contact) con {"apply": true|false, "value": "..."}; las fechas en «AAAA-MM-DD HH:MM». `lists` permite pasar los documentos exigidos (documents) y los criterios (criteria) a la licitación. Solo funciona con el análisis en «review»; repetirla no aplica nada dos veces.')]
#[IsDestructive(false)]
#[IsIdempotent]
class ConfirmBidAnalysisTool extends BaseTool
{
    protected ?array $record = ['tender_id', Tender::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'tender_id' => $schema->integer()->description('Id de la licitación.')->required(),
            'analysis_id' => $schema->integer()->description('Id del análisis.')->required(),
            ...$this->companySchema($schema),
            'fields' => $schema->object()->description('Datos a aplicar: {"clarification_deadline": {"apply": true, "value": "2026-09-17 23:59"}, ...}.')->required(),
            'lists' => $schema->object()->description('{"documents": true, "criteria": false}.'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $tender = $this->find(Tender::class, $request->get('tender_id'), 'la licitación');
        $this->authorize('update', $tender);

        $extraction = DocumentExtraction::query()
            ->where('tender_id', $tender->id)->where('kind', DocumentExtraction::KIND_BID)
            ->find((int) $request->get('analysis_id')) ?? throw new ToolError('No se encontró ese análisis en la licitación.');

        if (! in_array($extraction->status, [DocumentExtraction::REVIEW, DocumentExtraction::CONFIRMED], true)) {
            throw new ToolError('El análisis aún no está listo para revisar (estado: '.$extraction->statusLabel().').');
        }

        $fields = collect((array) $request->get('fields', []))->map(function ($field) {
            $field = (array) $field;
            if (isset($field['value']) && is_string($field['value'])) {
                $field['value'] = str_replace('T', ' ', $field['value']);
            }

            return ['apply' => filter_var($field['apply'] ?? false, FILTER_VALIDATE_BOOLEAN)] + $field;
        })->all();

        $lists = array_map(fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN), (array) $request->get('lists', []));
        $form = new BidReviewRequest;
        $data = $this->validate(['fields' => $fields, 'lists' => $lists], $form->rules(), $form->messages());

        $done = app(BidReviewService::class)->confirm($extraction, $tender, $data['fields'] ?? [], $data['lists'] ?? [], $user);

        return $this->ok([
            'applied' => $done,
            'tender' => Present::tender($tender->fresh(['institution:id,name', 'owner:id,name'])),
        ], $done ? 'Datos del pliego aplicados a la licitación; el calendario y los recordatorios se actualizaron.' : 'Este análisis ya se había confirmado: no se aplicó nada nuevo.');
    }
}
