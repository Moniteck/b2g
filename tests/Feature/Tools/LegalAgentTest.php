<?php

namespace Tests\Feature\Tools;

use App\Models\LegalConversation;
use App\Models\LegalMessage;
use App\Services\OpenAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegalAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => 'clave-de-prueba', 'services.openai.model' => 'modelo-de-prueba']);
    }

    private function fakeReply(string $content): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => $content]]]])]);
    }

    public function test_chat_creates_and_continues_a_personal_conversation(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        $this->fakeReply('Según el artículo 142, el plazo es de diez días hábiles.');

        $response = $this->actingInCompany($operator, $company)->postJson('/agente-legal/chat', ['message' => '¿Cómo impugno una adjudicación? <script>alert(1)</script>'])
            ->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.1.role', 'assistant');

        $conversationId = $response->json('conversation.id');
        $this->assertSame('¿Cómo impugno una adjudicación? <script>alert(1)</script>', LegalMessage::query()->orderBy('id')->first()->content);

        $this->actingInCompany($operator, $company)->postJson('/agente-legal/chat', ['conversation_id' => $conversationId, 'message' => '¿Y si me lo niegan?'])->assertOk();

        Http::assertSent(fn ($request) => count($request['messages']) === 4 && $request['messages'][0]['role'] === 'system'
            && $request['messages'][0]['content'] === OpenAIService::LEGAL_SYSTEM_PROMPT);
        $this->assertSame(4, LegalMessage::count());

        $this->actingInCompany($operator, $company)->get('/agente-legal')
            ->assertInertia(fn (Assert $page) => $page->component('LegalAgent/Index')->has('conversations', 1)->where('conversations.0.messages_count', 4));

        $this->actingInCompany($operator, $company)->getJson("/agente-legal/conversaciones/{$conversationId}")->assertOk()->assertJsonCount(4, 'messages');
        $this->actingInCompany($operator, $company)->deleteJson("/agente-legal/conversaciones/{$conversationId}")->assertOk();
        $this->assertSame(0, LegalConversation::count());
    }

    public function test_ai_failure_returns_a_clear_error_and_saves_nothing(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Unsupported parameter: max_tokens']], 400)]);

        $this->actingInCompany($operator, $company)->postJson('/agente-legal/chat', ['message' => 'Hola'])
            ->assertStatus(422)->assertJsonPath('message', fn ($message) => is_string($message) && $message !== '');

        $this->assertSame(0, LegalConversation::count());
        $this->assertSame(0, LegalMessage::count());

        config(['services.openai.key' => null]);
        $this->actingInCompany($operator, $company)->postJson('/agente-legal/escritos', [
            'type' => 'impugnacion', 'company' => 'Mi empresa', 'recipient' => 'SESAL', 'context' => 'Fuimos descalificados sin motivo en el proceso LPN-1.',
        ])->assertStatus(422)->assertJsonPath('message', fn ($message) => str_contains($message, 'no está configurada'));
    }

    public function test_writing_generates_a_draft_and_exports_an_escaped_pdf(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        $this->fakeReply('Señores SESAL: presentamos impugnación.');

        $this->actingInCompany($operator, $company)->postJson('/agente-legal/escritos', [
            'type' => 'impugnacion', 'company' => 'Mi empresa', 'recipient' => 'SESAL', 'context' => 'Fuimos descalificados sin motivo en el proceso LPN-1.',
        ])->assertOk()->assertJsonPath('title', 'Borrador de impugnación')->assertJsonPath('content', 'Señores SESAL: presentamos impugnación.');

        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'], 'LPN-1'));

        $this->actingInCompany($operator, $company)->postJson('/agente-legal/escritos', ['type' => 'otro', 'context' => 'corto'])
            ->assertStatus(422)->assertJsonValidationErrors(['type', 'company', 'recipient', 'context']);

        $this->actingInCompany($operator, $company)->post('/agente-legal/escritos/pdf', ['type' => 'impugnacion', 'content' => '<b>texto</b>'])
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $html = view('pdf.tools.text-document', ['title' => 'T', 'company' => [], 'generatedAt' => now(), 'content' => '<b>texto</b>
**Asunto**'])->render();
        $this->assertStringContainsString('&lt;b&gt;texto&lt;/b&gt;', $html);
        $this->assertStringContainsString('<strong>Asunto</strong>', $html);
        $this->assertStringNotContainsString('<b>texto</b>', $html);
    }

    public function test_viewer_cannot_use_ai_and_conversations_are_personal(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        [$viewer] = $this->memberOf($company, 'viewer');
        [$colleague] = $this->memberOf($company, 'operator');
        $conversation = LegalConversation::create(['user_id' => $operator->id, 'title' => 'Mía']);

        $this->actingInCompany($viewer, $company)->get('/agente-legal')->assertForbidden();
        $this->actingInCompany($viewer, $company)->postJson('/agente-legal/chat', ['message' => 'Hola'])->assertForbidden();

        $this->actingInCompany($colleague, $company)->getJson("/agente-legal/conversaciones/{$conversation->id}")->assertForbidden();
        $this->actingInCompany($colleague, $company)->postJson('/agente-legal/chat', ['conversation_id' => $conversation->id, 'message' => 'Hola'])->assertForbidden();
        $this->actingInCompany($colleague, $company)->get('/agente-legal')->assertInertia(fn (Assert $page) => $page->has('conversations', 0));
    }
}
