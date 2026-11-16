<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Mcp\McpConsentController;
use App\Jobs\ReadTenderCapture;
use App\Models\AuditLog;
use App\Models\Company;
use App\Rules\SafeExternalUrl;
use App\Services\Ai\AiTokenBudget;
use App\Services\Ai\BidDocumentAnalyzer;
use App\Services\Ai\OpenAIService;
use App\Services\System\AuditLogger;
use App\Services\Tenders\CaptureImportService;
use App\Services\Tenders\OcrReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Correcciones de la auditoría de seguridad que no cubren las pruebas de `tests/Security`:
 * cabeceras y CSP, sesión, límites, presupuesto de IA, captura en cola, auditoría y SSRF.
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_send_a_nonce_csp_and_inline_scripts_carry_it(): void
    {
        $response = $this->get('/login')->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=]+)'/", $csp);
        preg_match("/'nonce-([^']+)'/", $csp, $match);
        $this->assertStringContainsString('<script nonce="'.$match[1].'">', $response->getContent());
        $this->assertStringContainsString("worker-src 'self' blob:", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertNotNull($response->headers->get('Permissions-Policy'));
        $this->assertNull($response->headers->get('Strict-Transport-Security'), 'HSTS solo por HTTPS.');
    }

    public function test_login_always_lands_on_the_dashboard(): void
    {
        $this->memberOf(attributes: ['email' => 'ana@empresa.test', 'password' => 'ClaveValida123']);

        $this->get('/cotizaciones')->assertRedirect('/login'); // deja una URL «intended»
        $this->post('/login', ['email' => 'ana@empresa.test', 'password' => 'ClaveValida123'])->assertRedirect(route('dashboard'));
    }

    public function test_authenticated_responses_are_private_and_history_is_encrypted_and_cleared_on_logout(): void
    {
        [$user] = $this->memberOf();

        $page = $this->actingAs($user)->get('/')->assertOk();
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $page->headers->get('Cache-Control'));
        $this->assertTrue($page->viewData('page')['encryptHistory']);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertTrue($this->get('/login')->viewData('page')['clearHistory']);

        $this->assertStringNotContainsString('no-store', (string) $this->get('/login')->headers->get('Cache-Control'));
    }

    public function test_login_events_are_audited(): void
    {
        $this->memberOf(attributes: ['email' => 'ana@empresa.test', 'password' => 'ClaveValida123']);

        $this->post('/login', ['email' => 'ana@empresa.test', 'password' => 'Incorrecta999']);
        $this->post('/login', ['email' => 'ana@empresa.test', 'password' => 'ClaveValida123']);
        $this->post('/logout');

        foreach (['sesion.fallida', 'sesion.iniciada', 'sesion.cerrada'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), "Falta {$action}");
        }
        $this->assertStringNotContainsString('Incorrecta999', (string) json_encode(AuditLog::query()->pluck('changes')));

        foreach (range(1, 6) as $attempt) {
            $this->post('/login', ['email' => 'ana@empresa.test', 'password' => 'Incorrecta999']);
        }
        $this->assertTrue(AuditLog::query()->where('action', 'sesion.bloqueada')->exists());
    }

    public function test_login_failures_are_limited_per_email_from_any_ip_but_successes_do_not_count(): void
    {
        $this->memberOf(attributes: ['email' => 'ana@empresa.test', 'password' => 'ClaveValida123']);

        foreach (range(1, 15) as $attempt) {
            $this->post('/login', ['email' => 'ana@empresa.test', 'password' => 'ClaveValida123'])->assertRedirect(route('dashboard'));
            $this->post('/logout');
        }

        foreach (range(1, 20) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$attempt}"])->post('/login', ['email' => 'ana@empresa.test', 'password' => 'Incorrecta999']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->post('/login', ['email' => 'ana@empresa.test', 'password' => 'ClaveValida123'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_audit_hides_partially_matching_secret_keys(): void
    {
        [$user] = $this->memberOf();

        $log = app(AuditLogger::class)->record('prueba', null, ['client_secret' => 'x', 'access_token' => 'y', 'api_key' => 'z', 'nested' => ['password_hash' => 'w'], 'nombre' => 'Ana'], $user);

        $this->assertSame(['client_secret' => '[oculto]', 'access_token' => '[oculto]', 'api_key' => '[oculto]', 'nested' => ['password_hash' => '[oculto]'], 'nombre' => 'Ana'], $log->changes);
    }

    public function test_exports_and_company_switch_are_audited_and_throttled_with_a_spanish_toast(): void
    {
        [$admin, $company] = $this->memberOf();
        $other = Company::factory()->create();
        $admin->companies()->attach($other->id, ['role' => 'admin']);

        $this->actingInCompany($admin, $company)->get('/licitaciones/export/xlsx')->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'exporte.generado')->exists());

        $this->actingInCompany($admin, $company)->post('/empresa-activa', ['company_id' => $other->id]);
        $this->assertTrue(AuditLog::query()->where('action', 'sesion.empresa_cambiada')->exists());

        foreach (range(1, 20) as $attempt) {
            $this->get('/clientes/export/csv');
        }
        $this->from('/clientes')->get('/clientes/export/csv')->assertRedirect('/clientes')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Generaste muchos archivos seguidos'));
    }

    public function test_the_daily_token_budget_stops_ai_calls(): void
    {
        config(['services.openai.key' => 'sk-prueba', 'services.openai.daily_token_budget' => 1000]);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"fields":{},"lists":{}}']]], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 200, 'total_tokens' => 1100]])]);

        $openAI = app(OpenAIService::class)->forCompany(7);
        $openAI->json([['role' => 'user', 'content' => 'hola']]);
        $this->assertSame(1100, app(AiTokenBudget::class)->used(7));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('presupuesto diario');
        $openAI->json([['role' => 'user', 'content' => 'otra vez']]);
    }

    public function test_bid_analysis_refuses_too_many_pages_before_calling_the_ai(): void
    {
        config(['services.openai.key' => 'sk-prueba']);
        Http::fake();

        try {
            app(BidDocumentAnalyzer::class)->analyze(array_fill(0, 301, 'texto'), 1);
            $this->fail('Se esperaba el rechazo por páginas.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('301 páginas', $exception->getMessage());
        }

        try {
            app(BidDocumentAnalyzer::class)->analyze(array_fill(0, 250, str_repeat('Requisito técnico. ', 300)), 1);
            $this->fail('Se esperaba el rechazo por tramos.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('el máximo es 15', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_capture_is_read_in_the_queue_and_the_review_polls_until_ready(): void
    {
        Storage::fake('local');
        Queue::fake();
        [$admin, $company] = $this->memberOf();

        $response = $this->actingInCompany($admin, $company)->post('/licitaciones/importar', ['file' => UploadedFile::fake()->image('captura.png', 800, 600)]);
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        Queue::assertPushed(ReadTenderCapture::class, fn ($job) => $job->token === $token);

        $this->actingInCompany($admin, $company)->getJson("/licitaciones/importar/{$token}/estado")->assertOk()->assertJson(['pending' => true]);
        $this->actingInCompany($admin, $company)->get("/licitaciones/importar/{$token}")
            ->assertInertia(fn ($page) => $page->component('Tenders/ImportReview')->where('review.pending', true));

        $ocr = Mockery::mock(OcrReader::class);
        $ocr->shouldReceive('read')->once()->andReturn('texto sin ficha');
        $this->app->instance(OcrReader::class, $ocr);
        app(CaptureImportService::class)->process($token);

        $this->actingInCompany($admin, $company)->getJson("/licitaciones/importar/{$token}/estado")->assertJson(['pending' => false]);
        // Otra empresa u otro usuario no consulta el estado.
        [$intruder] = $this->memberOf($company, 'operator');
        $this->actingInCompany($intruder, $company)->getJson("/licitaciones/importar/{$token}/estado")->assertNotFound();
    }

    public function test_capture_rejects_oversized_dimensions(): void
    {
        Storage::fake('local');
        [$admin, $company] = $this->memberOf();

        $this->actingInCompany($admin, $company)->post('/licitaciones/importar', ['file' => UploadedFile::fake()->image('ancha.png', 10001, 10)])
            ->assertSessionHasErrors('file');
    }

    public function test_blessing_url_must_be_public_https(): void
    {
        $this->assertNull(SafeExternalUrl::problem('https://api.blessing.hn'));
        $this->assertNotNull(SafeExternalUrl::problem('http://api.blessing.hn'));
        $this->assertNotNull(SafeExternalUrl::problem('https://localhost:8080'));
        $this->assertNotNull(SafeExternalUrl::problem('https://127.0.0.1/api'));
        $this->assertNotNull(SafeExternalUrl::problem('https://10.0.0.5/api'));
        $this->assertNotNull(SafeExternalUrl::problem('https://169.254.169.254/latest'));
        $this->assertNotNull(SafeExternalUrl::problem('https://[::1]/api'));
    }

    public function test_mcp_consent_marks_dynamically_registered_clients_as_unverified(): void
    {
        $client = new Client(['name' => 'Cliente X']);
        [$user] = $this->memberOf();

        $this->assertTrue(McpConsentController::screen($user, $client, 'https://x.test/cb')['unverified']);
    }
}
