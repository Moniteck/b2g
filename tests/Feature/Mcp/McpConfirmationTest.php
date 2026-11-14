<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\RegisterOutcomeTool;
use App\Mcp\Tools\RemoveAttachmentTool;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\McpAuthorization;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Confirmación en dos pasos: el primer paso no cambia nada; el código es de un solo uso,
 * vence, y no sirve para otra acción, otro registro, otros datos u otra conexión.
 */
class McpConfirmationTest extends McpTestCase
{
    private Company $company;

    private User $admin;

    private Tender $tender;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        $this->tender = Tender::factory()->create(['company_id' => $this->company->id]);
        $this->document = Document::factory()->create(['company_id' => $this->company->id, 'kind' => 'proceso']);
        DocumentLink::create(['document_id' => $this->document->id, 'linkable_type' => Tender::class, 'linkable_id' => $this->tender->id, 'context' => 'tender']);
    }

    private function arguments(array $extra = []): array
    {
        return ['entity_type' => 'tender', 'entity_id' => $this->tender->id, 'document_id' => $this->document->id] + $extra;
    }

    public function test_first_call_returns_summary_and_code_without_changes(): void
    {
        $this->inSession($this->admin, $connection = $this->connect($this->admin, [$this->company]));

        $payload = $this->payload($this->tool(RemoveAttachmentTool::class, $this->arguments())->assertHasNoErrors());

        $this->assertSame('confirmation_required', $payload['status']);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $payload['confirmation_code']);
        $this->assertTrue($payload['summary']['deletes_document']);
        $this->assertDatabaseHas('documents', ['id' => $this->document->id]);

        $authorization = McpAuthorization::query()->sole();
        $this->assertSame($connection->id, $authorization->mcp_connection_id);
        $this->assertNotSame($payload['confirmation_code'], $authorization->code_hash);
    }

    public function test_code_confirms_once_and_cannot_be_reused(): void
    {
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));
        $code = $this->payload($this->tool(RemoveAttachmentTool::class, $this->arguments()))['confirmation_code'];

        $done = $this->payload($this->tool(RemoveAttachmentTool::class, $this->arguments(['confirmation_code' => $code]))->assertHasNoErrors());
        $this->assertTrue($done['document_deleted']);
        $this->assertSoftDeletedOrMissing();

        $this->tool(RemoveAttachmentTool::class, $this->arguments(['confirmation_code' => $code]))->assertHasErrors();
        $this->assertNotNull(McpAuthorization::query()->sole()->consumed_at);
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));
        $code = $this->payload($this->tool(RemoveAttachmentTool::class, $this->arguments()))['confirmation_code'];

        $this->travel(11)->minutes();

        $this->tool(RemoveAttachmentTool::class, $this->arguments(['confirmation_code' => $code]))->assertHasErrors(['venció']);
        $this->assertDatabaseHas('document_links', ['document_id' => $this->document->id]);
    }

    public function test_code_of_other_action_or_other_data_is_rejected(): void
    {
        $quote = Quote::factory()->create(['company_id' => $this->company->id, 'status' => 'Presentada']);
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));

        $quoteCode = $this->payload($this->tool(RegisterOutcomeTool::class, ['quote_id' => $quote->id, 'outcome' => 'Perdida', 'winner_name' => 'Otra S.A.', 'winner_amount' => 1000]))['confirmation_code'];
        $this->tool(RemoveAttachmentTool::class, $this->arguments(['confirmation_code' => $quoteCode]))->assertHasErrors(['no corresponde']);

        // Mismo código con datos distintos (otro ganador): rechazado y el resultado sigue Pendiente.
        $this->tool(RegisterOutcomeTool::class, ['quote_id' => $quote->id, 'outcome' => 'Perdida', 'winner_name' => 'Nosotros', 'winner_amount' => 1000, 'confirmation_code' => $quoteCode])
            ->assertHasErrors(['no corresponde']);
        $this->assertSame('Pendiente', $quote->fresh()->outcome);

        $this->tool(RemoveAttachmentTool::class, $this->arguments(['confirmation_code' => 'ZZZZ-ZZZZ']))->assertHasErrors(['no es válido']);
    }

    public function test_code_is_bound_to_the_connection_and_user(): void
    {
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));
        $code = $this->payload($this->tool(RemoveAttachmentTool::class, $this->arguments()))['confirmation_code'];

        [$colleague] = $this->memberOf($this->company, 'admin');
        $this->inSession($colleague, $this->connect($colleague, [$this->company]));

        $this->tool(RemoveAttachmentTool::class, $this->arguments(['confirmation_code' => $code]))->assertHasErrors(['no corresponde']);
        $this->assertDatabaseHas('document_links', ['document_id' => $this->document->id]);
    }

    public function test_outcome_requires_winner_and_never_issues_code_without_permission(): void
    {
        $quote = Quote::factory()->create(['company_id' => $this->company->id, 'status' => 'Presentada']);
        [$viewer] = $this->memberOf($this->company, 'viewer');
        $this->inSession($viewer, $this->connect($viewer, [$this->company]));

        $this->tool(RegisterOutcomeTool::class, ['quote_id' => $quote->id, 'outcome' => 'Ganada', 'awarded_amount' => 100])->assertHasErrors(['Sin permiso']);
        $this->assertDatabaseCount('mcp_authorizations', 0);

        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));
        $this->tool(RegisterOutcomeTool::class, ['quote_id' => $quote->id, 'outcome' => 'Perdida', 'winner_amount' => 10])->assertHasErrors(['winner_name']);
    }

    private function assertSoftDeletedOrMissing(): void
    {
        $this->assertTrue(Document::query()->withoutGlobalScopes()->find($this->document->id) === null);
    }
}
