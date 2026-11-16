<?php

namespace Tests\Feature\Pwa;

use App\Jobs\SendReminderPush;
use App\Models\PushSubscription;
use App\Models\ReminderDelivery;
use App\Models\Tender;
use App\Models\User;
use App\Services\Notifications\ReminderDispatcher;
use App\Services\Pwa\PushTransport;
use App\Services\Pwa\WebPushService;
use App\Support\Pwa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Aplicación instalable: suscripciones Web Push por dispositivo, envío (con un transporte falso),
 * limpieza de suscripciones caducadas, canal «push» de los recordatorios y canal desactivado sin
 * claves VAPID.
 */
class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private FakePushTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'America/Tegucigalpa'));

        config(['pwa.vapid' => [
            'subject' => 'mailto:qa@crm-b2g.test',
            'public_key' => 'BKeRUXvNQz1P2c_IVKWvFjd5tsWNOc4ZKCB4tu2mhF-MExVkmflq2H6TSAOc4UDtE33SfVJPEw21SFhaD7cyzuE',
            'private_key' => 'clave-privada-de-prueba',
        ]]);

        $this->transport = new FakePushTransport;
        $this->app->instance(WebPushService::class, new WebPushService($this->transport));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subscriptionPayload(string $device = 'a'): array
    {
        return [
            'endpoint' => "https://fcm.googleapis.com/fcm/send/dispositivo-{$device}",
            'keys' => [
                'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
                'auth' => 'tBHItJI5svbpez7KI4CCXg',
            ],
            'content_encoding' => 'aes128gcm',
        ];
    }

    private function subscribe(User $user, string $device = 'a'): PushSubscription
    {
        $this->actingAs($user)->postJson('/pwa/push/suscripciones', $this->subscriptionPayload($device))->assertSuccessful();

        return PushSubscription::query()->where('endpoint_hash', PushSubscription::hashEndpoint("https://fcm.googleapis.com/fcm/send/dispositivo-{$device}"))->firstOrFail();
    }

    public function test_a_user_subscribes_this_device_and_renewing_does_not_duplicate(): void
    {
        [$user] = $this->memberOf(null, 'viewer');

        $this->actingAs($user)->postJson('/pwa/push/suscripciones', $this->subscriptionPayload())
            ->assertCreated()->assertJson(['devices' => 1]);
        $this->actingAs($user)->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36')
            ->postJson('/pwa/push/suscripciones', $this->subscriptionPayload())
            ->assertOk()->assertJson(['devices' => 1]);

        $subscription = PushSubscription::query()->sole();
        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame('aes128gcm', $subscription->content_encoding);
        $this->assertArrayNotHasKey('auth_token', $subscription->toArray(), 'Las claves no se exponen al serializar.');
    }

    public function test_validation_rejects_non_https_endpoints_and_bad_keys(): void
    {
        [$user] = $this->memberOf();

        $payload = $this->subscriptionPayload();
        $payload['endpoint'] = 'http://inseguro.example.com/push';
        $payload['keys']['auth'] = 'no válida!';

        $this->actingAs($user)->postJson('/pwa/push/suscripciones', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint', 'keys.auth'])
            ->assertJsonPath('errors.endpoint.0', 'La dirección de la suscripción debe ser HTTPS.');

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_guests_cannot_subscribe(): void
    {
        $this->postJson('/pwa/push/suscripciones', $this->subscriptionPayload())->assertUnauthorized();
    }

    public function test_unsubscribe_removes_only_your_own_device_and_is_idempotent(): void
    {
        [$user, $company] = $this->memberOf();
        [$other] = $this->memberOf($company, 'operator');
        $this->subscribe($user, 'mio');
        $this->subscribe($other, 'ajeno');

        $this->actingAs($user)->deleteJson('/pwa/push/suscripciones', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/dispositivo-ajeno'])->assertForbidden();
        $this->actingAs($user)->deleteJson('/pwa/push/suscripciones', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/dispositivo-mio'])->assertOk()->assertJson(['devices' => 0]);
        $this->actingAs($user)->deleteJson('/pwa/push/suscripciones', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/dispositivo-mio'])->assertOk();

        $this->assertSame([$other->id], PushSubscription::query()->pluck('user_id')->all());
    }

    public function test_test_notification_is_sent_to_every_device_of_the_user(): void
    {
        [$user] = $this->memberOf();
        $this->subscribe($user, 'telefono');
        $this->subscribe($user, 'portatil');

        $this->actingAs($user)->postJson('/pwa/push/prueba')->assertOk()->assertJson(['result' => ['sent' => 2]]);

        $this->assertCount(2, $this->transport->sent);
        $payload = json_decode($this->transport->sent[0]['payload'], true);
        $this->assertSame('Aviso de prueba · CRM B2G', $payload['title']);
        $this->assertSame('/mi-cuenta', $payload['url']);
        $this->assertNotNull(PushSubscription::query()->first()->last_success_at);
    }

    public function test_expired_subscriptions_410_are_deleted(): void
    {
        [$user] = $this->memberOf();
        $alive = $this->subscribe($user, 'vivo');
        $gone = $this->subscribe($user, 'caducado');
        $this->transport->outcomes[$gone->endpoint] = ['status' => 'expired', 'reason' => '410 Gone'];

        $result = app(WebPushService::class)->sendToUser($user, ['title' => 'Hola', 'url' => '/']);

        $this->assertSame(['sent' => 1, 'expired' => 1, 'failed' => 0], $result);
        $this->assertSame([$alive->id], PushSubscription::query()->pluck('id')->all());
    }

    public function test_repeated_failures_discard_the_subscription_and_external_links_are_neutralised(): void
    {
        [$user] = $this->memberOf();
        $subscription = $this->subscribe($user);
        $this->transport->outcomes[$subscription->endpoint] = ['status' => 'failed', 'reason' => '500'];
        $push = app(WebPushService::class);

        for ($i = 1; $i < WebPushService::MAX_FAILURES; $i++) {
            $push->sendToUser($user, ['title' => 'x', 'url' => 'https://phishing.example.com']);
        }
        $this->assertSame(WebPushService::MAX_FAILURES - 1, $subscription->fresh()->failures);
        $this->assertSame('/', json_decode($this->transport->sent[0]['payload'], true)['url'], 'Solo se aceptan rutas internas.');

        $push->sendToUser($user, ['title' => 'x']);
        $this->assertNull($subscription->fresh());
    }

    public function test_reminders_go_out_by_push_to_subscribed_users_only(): void
    {
        Mail::fake();
        [$owner, $company] = $this->memberOf();
        [$colleague] = $this->memberOf($company, 'operator');
        $this->subscribe($owner, 'telefono');

        $tender = Tender::factory()->for($company)->create([
            'owner_id' => $owner->id,
            'reference' => 'LPN-PUSH-1',
            'clarification_deadline' => '2026-11-17 17:00:00',
        ]);

        $push = ReminderDelivery::query()->withoutGlobalScopes()->where('channel', 'push')->get();
        $this->assertNotEmpty($push, 'El plan incluye avisos push para quien tiene un dispositivo.');
        $this->assertSame([$owner->id], $push->pluck('user_id')->unique()->values()->all());

        $result = app(ReminderDispatcher::class)->dispatchDue();
        $this->assertSame(1, $result['push']);

        $delivery = ReminderDelivery::query()->withoutGlobalScopes()->where('channel', 'push')->where('status', 'Enviado')->sole();
        $this->assertSame($owner->id, $delivery->user_id);
        $payload = json_decode($this->transport->sent[0]['payload'], true);
        $this->assertStringContainsString('LPN-PUSH-1', $payload['title']);
        $this->assertSame('/licitaciones?licitacion='.$tender->id, $payload['url']);
        $this->assertArrayNotHasKey('push', app(ReminderDispatcher::class)->dispatchDue(), 'No sale dos veces.');
        $this->assertCount(1, $this->transport->sent);
        $this->assertNotContains($colleague->id, ReminderDelivery::query()->withoutGlobalScopes()->where('channel', 'push')->pluck('user_id')->all());
    }

    public function test_without_vapid_keys_the_channel_is_disabled_without_errors(): void
    {
        [$owner, $company] = $this->memberOf();
        $this->subscribe($owner);
        config(['pwa.vapid.private_key' => null]);
        Mail::fake();

        $this->actingAs($owner)->postJson('/pwa/push/suscripciones', $this->subscriptionPayload('b'))->assertStatus(409)
            ->assertJson(['message' => 'Las notificaciones push no están configuradas en el servidor.']);
        $this->actingAs($owner)->postJson('/pwa/push/prueba')->assertStatus(409);

        Tender::factory()->for($company)->create(['owner_id' => $owner->id, 'clarification_deadline' => '2026-11-17 17:00:00']);
        $this->assertSame(0, ReminderDelivery::query()->withoutGlobalScopes()->where('channel', 'push')->count());
        $this->assertSame(['sent' => 0, 'expired' => 0, 'failed' => 0], app(WebPushService::class)->sendToUser($owner, ['title' => 'x']));
        $this->assertSame([], $this->transport->sent);
        $this->assertNull(Pwa::vapidPublicKey());

        // Un aviso push ya programado cuando se quitaron las claves se cancela con motivo.
        $delivery = ReminderDelivery::query()->withoutGlobalScopes()->where('channel', 'correo')->firstOrFail()->replicate();
        $delivery->forceFill(['channel' => 'push', 'idempotency_key' => 'prueba-push', 'status' => 'Encolado', 'user_id' => $owner->id])->save();
        (new SendReminderPush($delivery->id))->handle(app(WebPushService::class));
        $this->assertSame('Cancelado', $delivery->fresh()->status);
    }

    public function test_the_page_head_exposes_manifest_and_skips_the_service_worker_in_local(): void
    {
        [$user, $company] = $this->memberOf();

        $html = $this->actingInCompany($user, $company)->get('/mi-cuenta')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $html);
        $this->assertStringContainsString('viewport-fit=cover', $html);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', $html);
        $this->assertStringContainsString('name="pwa-vapid"', $html);
        $this->assertStringNotContainsString('name="pwa-sw"', $html, 'En pruebas y desarrollo el service worker no se registra.');
    }

    public function test_manifest_and_offline_page_are_valid(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('CRM B2G', $manifest['short_name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        foreach (array_merge($manifest['icons'], $manifest['screenshots']) as $image) {
            $this->assertFileExists(public_path(ltrim($image['src'], '/')));
        }
        $this->assertCount(4, $manifest['shortcuts']);
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
        $this->assertStringContainsString('Sin conexión', (string) file_get_contents(public_path('offline.html')));
    }
}

class FakePushTransport implements PushTransport
{
    /** @var array<int, array{endpoint: string, payload: string, options: array}> */
    public array $sent = [];

    /** @var array<string, array{status: string, reason: string|null}> */
    public array $outcomes = [];

    public function send(PushSubscription $subscription, string $payload, array $options = []): array
    {
        $this->sent[] = ['endpoint' => $subscription->endpoint, 'payload' => $payload, 'options' => $options];

        return $this->outcomes[$subscription->endpoint] ?? ['status' => 'sent', 'reason' => null];
    }
}
