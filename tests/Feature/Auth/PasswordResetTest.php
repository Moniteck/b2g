<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Recuperar la contraseña por correo: enlace con token (60 min), misma respuesta exista o no el
 * correo, límite de peticiones, cierre de sesiones y tokens al restablecer, y auditoría.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_the_same_answer_whether_or_not_the_email_exists(): void
    {
        [$user] = $this->memberOf(attributes: ['email' => 'ana@empresa.test']);

        $existing = $this->post('/olvide-contrasena', ['email' => 'ana@empresa.test']);
        $missing = $this->post('/olvide-contrasena', ['email' => 'nadie@empresa.test']);

        $existing->assertRedirect('/login')->assertSessionHasNoErrors();
        $missing->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->assertSame(session('status'), $this->app['session']->get('status'));
        $this->assertStringContainsString('Si el correo tiene una cuenta', (string) session('status'));

        Mail::assertQueued(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->hasTo($user->email) && str_contains($mail->url, '/restablecer-contrasena/'));
        Mail::assertQueuedCount(1);
        $this->assertSame(2, AuditLog::query()->where('action', 'contrasena.recuperacion_solicitada')->count());
    }

    public function test_the_reset_screen_uses_the_animated_access_scene(): void
    {
        $this->get('/restablecer-contrasena/abc123?email=ana@empresa.test')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Login')->where('mode', 'reset')
                ->where('reset.token', 'abc123')->where('reset.email', 'ana@empresa.test'));
    }

    public function test_resetting_changes_the_password_and_closes_every_session(): void
    {
        config(['session.driver' => 'database']);
        [$user] = $this->memberOf(attributes: ['email' => 'ana@empresa.test', 'password' => 'ClaveVieja123']);
        DB::table('sessions')->insert(['id' => 'sesion-robada', 'user_id' => $user->id, 'ip_address' => '1.2.3.4', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->timestamp]);
        $token = Password::broker()->createToken($user);

        $this->post('/restablecer-contrasena', [
            'token' => $token, 'email' => 'ana@empresa.test', 'password' => 'ClaveNueva456', 'password_confirmation' => 'ClaveNueva456',
        ])->assertRedirect('/login')->assertSessionHas('status');

        $this->assertTrue(Hash::check('ClaveNueva456', $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'contrasena.restablecida_por_correo')->exists());

        // El token sirve una sola vez.
        $this->post('/restablecer-contrasena', [
            'token' => $token, 'email' => 'ana@empresa.test', 'password' => 'OtraClave789', 'password_confirmation' => 'OtraClave789',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('ClaveNueva456', $user->fresh()->password));
    }

    public function test_an_invalid_or_expired_token_is_rejected_in_spanish(): void
    {
        [$user] = $this->memberOf(attributes: ['email' => 'ana@empresa.test', 'password' => 'ClaveVieja123']);
        $token = Password::broker()->createToken($user);
        $this->travel(61)->minutes();

        $this->post('/restablecer-contrasena', [
            'token' => $token, 'email' => 'ana@empresa.test', 'password' => 'ClaveNueva456', 'password_confirmation' => 'ClaveNueva456',
        ])->assertRedirect()->assertSessionHasErrors(['email' => 'El enlace venció o no corresponde a este correo. Pide uno nuevo desde «¿Olvidaste tu contraseña?».']);

        $this->post('/restablecer-contrasena', ['token' => 'x', 'email' => 'ana@empresa.test', 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('ClaveVieja123', $user->fresh()->password));
    }

    public function test_requests_for_a_link_are_throttled(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->post('/olvide-contrasena', ['email' => 'ana@empresa.test'])->assertSessionHasNoErrors();
        }

        $this->post('/olvide-contrasena', ['email' => 'ana@empresa.test'])->assertRedirect()->assertSessionHasErrors('throttle');
    }
}
