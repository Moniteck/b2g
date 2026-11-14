<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\AddContractFollowUpTool;
use App\Mcp\Tools\ChangeContractStatusTool;
use App\Mcp\Tools\ChangeQuoteStatusTool;
use App\Mcp\Tools\CreateOpportunityTool;
use App\Mcp\Tools\CreateQuoteTool;
use App\Mcp\Tools\GetContractTool;
use App\Mcp\Tools\GetOpportunityTool;
use App\Mcp\Tools\GetQuoteTool;
use App\Mcp\Tools\MarkDeliveryDeliveredTool;
use App\Mcp\Tools\RegisterOutcomeTool;
use App\Mcp\Tools\RevertOutcomeTool;
use App\Mcp\Tools\SaveContractDeliveryTool;
use App\Mcp\Tools\SetParticipantsTool;
use App\Mcp\Tools\UpdateContractTool;
use App\Mcp\Tools\UpdateQuoteTool;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Institution;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo comercial por MCP sobre las APIs de Cotizaciones y Post-adjudicación:
 * cotización → Presentada → participantes → Ganada (dos pasos) → cliente y contrato →
 * entregas, seguimiento y cierre; y la reversión con su bloqueo por actividad.
 */
class McpCommercialFlowTest extends McpTestCase
{
    private Company $company;

    private User $admin;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        $this->institution = Institution::factory()->create();
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));
    }

    private function confirm(string $tool, array $arguments): array
    {
        $first = $this->payload($this->tool($tool, $arguments)->assertHasNoErrors());
        $this->assertSame('confirmation_required', $first['status'] ?? null, json_encode($first));

        return $this->payload($this->tool($tool, $arguments + ['confirmation_code' => $first['confirmation_code']])->assertHasNoErrors());
    }

    private function presentedQuote(): int
    {
        $created = $this->payload($this->tool(CreateQuoteTool::class, [
            'institution_id' => $this->institution->id,
            'items' => [['description' => 'Llanta 11R22.5', 'quantity' => 4, 'unit' => 'Unidad', 'unit_price' => 1000]],
        ])->assertHasNoErrors());
        $quoteId = $created['quote']['id'];

        $this->tool(UpdateQuoteTool::class, ['quote_id' => $quoteId, 'notes' => 'Entrega en 15 días'])->assertHasNoErrors();
        $this->tool(ChangeQuoteStatusTool::class, ['quote_id' => $quoteId, 'status' => 'Emitida'])->assertHasNoErrors();
        $this->tool(ChangeQuoteStatusTool::class, ['quote_id' => $quoteId, 'status' => 'Presentada'])->assertHasNoErrors();

        return $quoteId;
    }

    public function test_quote_to_contract_to_close(): void
    {
        $quoteId = $this->presentedQuote();
        $quote = Quote::query()->withoutGlobalScopes()->findOrFail($quoteId);
        $this->assertSame('Presentada', $quote->status);
        $this->assertEqualsWithDelta(4000, (float) $quote->subtotal, 0.01);

        $participants = $this->payload($this->tool(SetParticipantsTool::class, ['quote_id' => $quoteId, 'participants' => [
            ['name' => 'Nosotros', 'amount' => (float) $quote->total, 'is_ours' => true],
            ['name' => 'Competidor S.A.', 'amount' => 5200],
        ]])->assertHasNoErrors());
        $this->assertSame(2, $participants['summary']['count']);

        $won = $this->confirm(RegisterOutcomeTool::class, ['quote_id' => $quoteId, 'outcome' => 'Ganada', 'awarded_amount' => 4400, 'awarded_at' => now()->toDateString()]);
        $this->assertSame('Ganada', $won['result']['quote']['outcome']);
        $contractId = $won['result']['contract']['id'];
        $this->assertSame(1, Client::query()->withoutGlobalScopes()->count());

        // Repetir no duplica: el servicio responde «ya aplicado».
        $again = $this->confirm(RegisterOutcomeTool::class, ['quote_id' => $quoteId, 'outcome' => 'Ganada', 'awarded_amount' => 4400, 'awarded_at' => now()->toDateString()]);
        $this->assertTrue($again['result']['already_applied']);
        $this->assertSame(1, Contract::query()->withoutGlobalScopes()->count());

        $this->tool(UpdateContractTool::class, ['contract_id' => $contractId, 'number' => 'CT-001-2026', 'duration_days' => 30, 'start_date' => now()->toDateString()])->assertHasNoErrors();
        $this->tool(ChangeContractStatusTool::class, ['contract_id' => $contractId, 'status' => 'En ejecución'])->assertHasNoErrors();

        $delivery = $this->payload($this->tool(SaveContractDeliveryTool::class, ['contract_id' => $contractId, 'description' => 'Llantas', 'quantity' => 4, 'due_date' => now()->addDays(10)->toDateString()])->assertHasNoErrors());
        $delivered = $this->payload($this->tool(MarkDeliveryDeliveredTool::class, ['contract_id' => $contractId, 'delivery_id' => $delivery['delivery']['id'], 'delivered_at' => now()->toDateString()])->assertHasNoErrors());
        $this->assertTrue($delivered['all_delivered']);
        $this->tool(AddContractFollowUpTool::class, ['contract_id' => $contractId, 'type' => 'Llamada', 'description' => 'Confirmó recepción', 'next_action' => 'Facturar', 'next_action_at' => now()->addDay()->toDateString()])->assertHasNoErrors();

        // Con actividad en el contrato ya no se revierte (lo decide AdjudicationService).
        $this->tool(RevertOutcomeTool::class, ['quote_id' => $quoteId, 'reason' => 'Error de captura'])->assertHasErrors();

        $this->tool(ChangeContractStatusTool::class, ['contract_id' => $contractId, 'status' => 'Producto entregado'])->assertHasNoErrors();
        $closed = $this->confirm(ChangeContractStatusTool::class, ['contract_id' => $contractId, 'status' => 'Cerrado']);
        $this->assertSame('Cerrado', $closed['contract']['status']);

        $detail = $this->payload($this->tool(GetContractTool::class, ['contract_id' => $contractId])->assertHasNoErrors());
        $this->assertCount(1, $detail['deliveries']);
        $this->assertCount(1, $detail['follow_ups']);
        $this->assertSame('CT-001-2026', $detail['contract']['number']);
    }

    public function test_revert_without_activity_and_operator_limits(): void
    {
        $quoteId = $this->presentedQuote();
        $this->confirm(RegisterOutcomeTool::class, ['quote_id' => $quoteId, 'outcome' => 'Ganada', 'awarded_amount' => 4000, 'awarded_at' => now()->toDateString()]);

        [$operator] = $this->memberOf($this->company, 'operator');
        $this->inSession($operator, $this->connect($operator, [$this->company]));
        $this->tool(RevertOutcomeTool::class, ['quote_id' => $quoteId, 'reason' => 'Se anuló el proceso'])->assertHasErrors(['Sin permiso']);
        $contractId = Contract::query()->withoutGlobalScopes()->value('id');
        $this->tool(ChangeContractStatusTool::class, ['contract_id' => $contractId, 'status' => 'Cancelado', 'reason' => 'x'])->assertHasErrors(['Sin permiso']);

        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));
        $reverted = $this->confirm(RevertOutcomeTool::class, ['quote_id' => $quoteId, 'reason' => 'Se anuló el proceso']);
        $this->assertSame('Cancelado', $reverted['result']['contract']['status']);
        $this->assertSame('Pendiente', Quote::query()->withoutGlobalScopes()->find($quoteId)->outcome);

        $quote = $this->payload($this->tool(GetQuoteTool::class, ['quote_id' => $quoteId])->assertHasNoErrors());
        $this->assertSame('Presentada', $quote['quote']['status']);
    }

    public function test_opportunity_without_quote_can_be_lost_with_winner(): void
    {
        $created = $this->payload($this->tool(CreateOpportunityTool::class, ['title' => 'Compra de llantas', 'institution_id' => $this->institution->id, 'stage' => 'oferta_presentada', 'amount' => 9000])->assertHasNoErrors());
        $id = $created['opportunity']['id'];

        $this->tool(CreateOpportunityTool::class, ['title' => 'Atajo', 'stage' => 'adjudicado'])->assertHasErrors(['stage']);

        $this->confirm(RegisterOutcomeTool::class, ['opportunity_id' => $id, 'outcome' => 'Perdida', 'winner_name' => 'Competidor S.A.', 'winner_amount' => 8500]);

        $detail = $this->payload($this->tool(GetOpportunityTool::class, ['opportunity_id' => $id])->assertHasNoErrors());
        $this->assertSame('no_adjudicado', $detail['opportunity']['stage']);
        $this->assertSame('Perdida', $detail['participants']['outcome']);
        $this->assertSame(0, Client::query()->withoutGlobalScopes()->count());
    }
}
