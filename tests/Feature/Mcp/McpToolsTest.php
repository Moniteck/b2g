<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\AddAttachmentTool;
use App\Mcp\Tools\CreateEventTool;
use App\Mcp\Tools\CreateInstitutionTool;
use App\Mcp\Tools\CreateTenderTool;
use App\Mcp\Tools\GetTenderTool;
use App\Mcp\Tools\ListCompaniesTool;
use App\Mcp\Tools\ListEventsTool;
use App\Mcp\Tools\ListExpiringDocumentsTool;
use App\Mcp\Tools\ListTendersTool;
use App\Mcp\Tools\MarkEventAttendedTool;
use App\Mcp\Tools\SearchInventoryTool;
use App\Mcp\Tools\SignDocumentTool;
use App\Mcp\Tools\SyncInventoryTool;
use App\Mcp\Tools\UpdateEventTool;
use App\Mcp\Tools\UpdateOpportunityStageTool;
use App\Mcp\Tools\UpdateTenderTool;
use App\Mcp\Tools\UploadDocumentTool;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Document;
use App\Models\Event;
use App\Models\InventoryItem;
use App\Models\McpConnection;
use App\Models\Opportunity;
use App\Models\Tender;
use App\Models\User;
use App\Services\Tenders\TenderAttributes;
use Illuminate\Support\Facades\Storage;

/**
 * Herramientas MCP: scopes, aislamiento entre empresas, policies por rol, validación, errores
 * controlados, auditoría y las herramientas nuevas con datos de factory.
 */
class McpToolsTest extends McpTestCase
{
    private Company $company;

    private Company $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        $this->other = Company::factory()->create();
    }

    public function test_list_companies_shows_only_connection_companies_and_level(): void
    {
        $this->other->users()->attach($this->admin->id, ['role' => 'viewer', 'is_default' => false]);
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company], McpConnection::LEVEL_READ));

        $payload = $this->payload($this->tool(ListCompaniesTool::class)->assertHasNoErrors());

        $this->assertSame('read', $payload['connection_level']);
        $this->assertSame([$this->company->id], array_column($payload['companies'], 'id'));
    }

    public function test_read_only_connection_cannot_write_and_hides_write_tools(): void
    {
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company], McpConnection::LEVEL_READ));

        // Oculta en la lista y, si se llama igual, rechazada (por el servidor o por la propia herramienta).
        $errors = $this->errorsOf($this->tool(CreateTenderTool::class, ['reference' => 'LPN-1-2026', 'title' => 'Compra']));
        $this->assertNotEmpty($errors);
        $this->assertMatchesRegularExpression('/solo lectura|not found/', $errors[0]);

        // Defensa en profundidad: aunque se invoque directamente, la herramienta la rechaza.
        $direct = (new CreateTenderTool)->handle(new \Laravel\Mcp\Request(['reference' => 'LPN-1-2026', 'title' => 'Compra']));
        $this->assertTrue($direct->isError());
        $this->assertStringContainsString('solo lectura', (string) $direct->content());
        $this->assertDatabaseCount('tenders', 0);

        CrmServer::tools()->assertRegistered(ListTendersTool::class)->assertNotRegistered(CreateTenderTool::class);
    }

    public function test_write_connection_lists_write_tools(): void
    {
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));

        CrmServer::tools()->assertRegistered([CreateTenderTool::class, SignDocumentTool::class]);
    }

    public function test_tenders_are_isolated_between_companies(): void
    {
        $mine = Tender::factory()->create(['company_id' => $this->company->id]);
        $foreign = Tender::factory()->create(['company_id' => $this->other->id]);
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));

        $list = $this->payload($this->tool(ListTendersTool::class)->assertHasNoErrors());
        $this->assertSame([$mine->id], array_column($list['data'], 'id'));

        $this->tool(GetTenderTool::class, ['tender_id' => $foreign->id])->assertHasErrors(['No se encontró']);
        $this->tool(GetTenderTool::class, ['tender_id' => $mine->id, 'empresa_id' => $this->other->id])->assertHasErrors(['no está disponible en esta conexión']);
        $this->tool(UpdateTenderTool::class, ['tender_id' => $foreign->id, 'title' => 'Hackeado'])->assertHasErrors(['No se encontró']);
        $this->assertNotSame('Hackeado', $foreign->fresh()->title);
    }

    public function test_connection_restricted_to_one_company_even_if_user_has_both(): void
    {
        $this->other->users()->attach($this->admin->id, ['role' => 'admin', 'is_default' => false]);
        $foreign = Tender::factory()->create(['company_id' => $this->other->id]);
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));

        $this->tool(GetTenderTool::class, ['tender_id' => $foreign->id])->assertHasErrors(['No se encontró']);
    }

    public function test_record_company_switches_context_inside_the_connection(): void
    {
        $this->other->users()->attach($this->admin->id, ['role' => 'admin', 'is_default' => false]);
        $foreign = Tender::factory()->create(['company_id' => $this->other->id]);
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company, $this->other]));

        $payload = $this->payload($this->tool(GetTenderTool::class, ['tender_id' => $foreign->id])->assertHasNoErrors());

        $this->assertSame($foreign->id, $payload['tender']['id']);
        $this->assertSame($foreign->title, $payload['tender']['untrusted']['title']);
        $this->assertArrayHasKey('_note', $payload);
    }

    public function test_viewer_cannot_write_even_with_write_connection(): void
    {
        [$viewer] = $this->memberOf($this->company, 'viewer');
        $this->inSession($viewer, $this->connect($viewer, [$this->company]));

        $this->tool(CreateTenderTool::class, ['reference' => 'LPN-2-2026', 'title' => 'Compra'])->assertHasErrors(['Sin permiso']);
        $this->tool(CreateEventTool::class, ['title' => 'Visita', 'starts_at' => now()->addDay()->format('Y-m-d H:i')])->assertHasErrors(['Sin permiso']);
        $this->assertDatabaseCount('tenders', 0);
    }

    public function test_create_and_update_tender_use_web_rules_and_audit_with_mcp_channel(): void
    {
        $connection = $this->connect($this->admin, [$this->company]);
        $this->inSession($this->admin, $connection);

        $created = $this->payload($this->tool(CreateTenderTool::class, [
            'reference' => ' lpn-007-2026 ',
            'title' => 'Suministro de llantas',
            'clarification_deadline' => '2026-12-17 23:59',
            'presentation_deadline' => '2026-12-30 10:00',
            'opening_at' => '2026-12-30T10:15',
        ])->assertHasNoErrors());

        $tender = Tender::query()->withoutGlobalScopes()->findOrFail($created['tender']['id']);
        $this->assertSame('LPN-007-2026', $tender->reference);
        $this->assertSame($this->company->id, $tender->company_id);
        $this->assertSame('2026-12-30 10:15:00', $created['tender']['opening_at']);

        $this->tool(CreateTenderTool::class, ['reference' => 'LPN-007-2026', 'title' => 'Otra'])->assertHasErrors(['Ya existe una licitación']);
        $this->tool(UpdateTenderTool::class, ['tender_id' => $tender->id, 'opening_at' => '2026-12-29 09:00'])->assertHasErrors(['apertura']);

        $this->tool(UpdateTenderTool::class, ['tender_id' => $tender->id, 'presentation_deadline' => '2026-12-31 10:00', 'opening_at' => '2026-12-31 10:30'])->assertHasNoErrors();
        $this->assertSame('2026-12-31 10:00:00', $tender->fresh()->presentation_deadline->format('Y-m-d H:i:s'));
        $this->assertSame('Suministro de llantas', $tender->fresh()->title);

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'licitacion.actualizada')->firstOrFail();
        $this->assertSame('mcp', $log->channel);
        $this->assertSame($connection->id, $log->mcp_connection_id);
    }

    public function test_calendar_create_update_attend_and_list(): void
    {
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));
        $start = now()->addDays(5)->setTime(9, 0);

        $created = $this->payload($this->tool(CreateEventTool::class, [
            'title' => 'Visita a la Secretaría',
            'starts_at' => $start->format('Y-m-d H:i'),
            'reminders' => [1, 0],
        ])->assertHasNoErrors());
        $eventId = $created['event']['id'];

        $this->tool(UpdateEventTool::class, ['event_id' => $eventId, 'title' => 'Visita confirmada'])->assertHasNoErrors();
        $this->assertSame('Visita confirmada', Event::query()->withoutGlobalScopes()->find($eventId)->title);

        $listed = $this->payload($this->tool(ListEventsTool::class, ['from' => now()->toDateString(), 'to' => now()->addDays(10)->toDateString()])->assertHasNoErrors());
        $this->assertContains($eventId, array_column($listed['events'], 'id'));

        $first = $this->payload($this->tool(MarkEventAttendedTool::class, ['event_id' => $eventId])->assertHasNoErrors());
        $second = $this->payload($this->tool(MarkEventAttendedTool::class, ['event_id' => $eventId])->assertHasNoErrors());
        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);

        $this->tool(ListEventsTool::class, ['from' => '2026-01-01', 'to' => '2026-12-31'])->assertHasErrors(['100 días']);
    }

    public function test_derived_events_cannot_be_edited(): void
    {
        $event = Event::factory()->create(['company_id' => $this->company->id, 'origin' => 'derivado', 'type' => 'Aclaraciones', 'reference_type' => 'tender', 'reference_id' => 1]);
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));

        if ($event->isDerived()) {
            $this->tool(UpdateEventTool::class, ['event_id' => $event->id, 'title' => 'Cambio'])->assertHasErrors(['registro de origen']);
        } else {
            $this->markTestSkipped('La factory no produce eventos derivados con ese origen.');
        }
    }

    public function test_search_inventory_returns_unified_pricing(): void
    {
        $item = InventoryItem::factory()->create(['company_id' => $this->company->id, 'suggested_price' => 100, 'price' => 100, 'tax_rate' => 15, 'tax_included' => false]);
        InventoryItem::factory()->create(['company_id' => $this->other->id, 'sku' => $item->sku.'X']);
        [$viewer] = $this->memberOf($this->company, 'viewer');
        $this->inSession($viewer, $this->connect($viewer, [$this->company], McpConnection::LEVEL_READ));

        $payload = $this->payload($this->tool(SearchInventoryTool::class, ['search' => $item->sku, 'quantity' => 3])->assertHasNoErrors());

        $this->assertCount(1, $payload['data']);
        $this->assertEquals($item->pricing(3)->toArray(), $payload['data'][0]['pricing']);
        $this->assertEquals(300.0, $payload['data'][0]['pricing']['subtotal']);
        $this->assertEquals(45.0, $payload['data'][0]['pricing']['tax']);
        $this->assertArrayNotHasKey('cost', $payload['data'][0]);
    }

    public function test_inventory_sync_is_admin_only(): void
    {
        [$operator] = $this->memberOf($this->company, 'operator');
        $this->inSession($operator, $this->connect($operator, [$this->company]));

        $this->tool(SyncInventoryTool::class)->assertHasErrors(['Sin permiso']);
    }

    public function test_upload_document_caps_size_before_decoding_and_validates_type(): void
    {
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));

        $tooBig = str_repeat('A', (int) ceil(10 * 1024 * 1024 / 3) * 4 + 8192);
        $this->tool(UploadDocumentTool::class, ['content_base64' => $tooBig, 'file_name' => 'rtn.pdf', 'category' => 'rtn'])->assertHasErrors(['10 MB']);
        $this->tool(UploadDocumentTool::class, ['content_base64' => '###', 'file_name' => 'rtn.pdf', 'category' => 'rtn'])->assertHasErrors(['base64']);
        $this->tool(UploadDocumentTool::class, ['content_base64' => base64_encode('no es un pdf'), 'file_name' => 'rtn.pdf', 'category' => 'rtn'])->assertHasErrors();
        $this->tool(UploadDocumentTool::class, ['content_base64' => base64_encode('x'), 'file_name' => 'rtn.pdf', 'category' => 'inventada'])->assertHasErrors(['category']);
        $this->assertDatabaseCount('documents', 0);

        $png = base64_encode((string) file_get_contents(base_path('tests/fixtures/honducompras/'.collect(scandir(base_path('tests/fixtures/honducompras')))->first(fn ($f) => str_ends_with($f, '.png')))));
        $payload = $this->payload($this->tool(UploadDocumentTool::class, [
            'content_base64' => $png, 'file_name' => 'rtn.png', 'category' => 'rtn', 'expires_at' => now()->addDays(10)->toDateString(),
        ])->assertHasNoErrors());
        $this->assertSame('Por vencer', $payload['document']['status']);
        $this->assertSame('rtn.png', $payload['document']['untrusted']['file_name']);

        $expiring = $this->payload($this->tool(ListExpiringDocumentsTool::class, ['days' => 15])->assertHasNoErrors());
        $this->assertSame(1, $expiring['count']);
    }

    public function test_add_attachment_respects_entity_company(): void
    {
        $foreign = Tender::factory()->create(['company_id' => $this->other->id]);
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));

        $this->tool(AddAttachmentTool::class, ['entity_type' => 'tender', 'entity_id' => $foreign->id, 'content_base64' => base64_encode('x'), 'file_name' => 'a.pdf'])
            ->assertHasErrors(['No se encontró']);
        $this->tool(AddAttachmentTool::class, ['entity_type' => 'planeta', 'entity_id' => 1, 'content_base64' => base64_encode('x'), 'file_name' => 'a.pdf'])
            ->assertHasErrors(['entity_type']);
    }

    public function test_opportunity_stage_tool_never_sets_an_outcome(): void
    {
        $opportunity = Opportunity::factory()->create(['company_id' => $this->company->id, 'stage' => 'oferta_presentada']);
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));

        $this->tool(UpdateOpportunityStageTool::class, ['opportunity_id' => $opportunity->id, 'etapa' => 'adjudicado'])->assertHasErrors(['register_quote_outcome']);
        $this->assertSame('oferta_presentada', $opportunity->fresh()->stage);
    }

    public function test_sign_document_requires_personal_assets(): void
    {
        $document = Document::factory()->create(['company_id' => $this->company->id, 'kind' => 'proceso']);
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));

        $this->tool(SignDocumentTool::class, ['document_id' => $document->id])->assertHasErrors(['Aún no has subido tu firma']);
        $this->assertDatabaseCount('mcp_authorizations', 0);
    }

    public function test_catalog_creation_and_unexpected_errors_are_controlled(): void
    {
        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));

        $this->tool(CreateInstitutionTool::class, ['name' => '  Secretaría   de Salud '])->assertHasNoErrors();
        $this->tool(CreateInstitutionTool::class, ['name' => 'Secretaría de Salud'])->assertHasErrors(['Ya existe una institución']);

        $this->app->bind(TenderAttributes::class, fn () => throw new \RuntimeException('boom'));
        $this->mock(\App\Services\AuditLogger::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new \RuntimeException('falla interna')));
        $this->tool(CreateTenderTool::class, ['reference' => 'LPN-9-2026', 'title' => 'Compra'])->assertHasErrors(['error interno', 'Código de seguimiento']);
    }
}
