<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pwa\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Services\AuditLogger;
use App\Services\Notifications\ReminderService;
use App\Services\Pwa\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «Recibir avisos en este dispositivo» (Mi cuenta). Respuestas JSON para `resources/js/pwa/push.js`.
 *
 *  POST   /pwa/push/suscripciones  alta o renovación de la suscripción de este dispositivo
 *  DELETE /pwa/push/suscripciones  baja (por endpoint)
 *  POST   /pwa/push/prueba         aviso de prueba a los dispositivos del usuario
 */
class PushSubscriptionController extends Controller
{
    public function __construct(protected WebPushService $push) {}

    public function store(StorePushSubscriptionRequest $request, AuditLogger $audit, ReminderService $reminders): JsonResponse
    {
        $this->authorize('create', PushSubscription::class);

        if (! $this->push->enabled()) {
            return $this->disabled();
        }

        $user = $request->user();
        $endpoint = (string) $request->validated('endpoint');
        $hash = PushSubscription::hashEndpoint($endpoint);

        [$subscription, $firstDevice] = DB::transaction(function () use ($request, $user, $endpoint, $hash) {
            $firstDevice = ! PushSubscription::query()->where('user_id', $user->id)->lockForUpdate()->exists();

            // El endpoint identifica al dispositivo: si otra persona lo tenía (equipo compartido), pasa a quien inició sesión.
            $subscription = PushSubscription::query()->updateOrCreate(['endpoint_hash' => $hash], [
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'public_key' => (string) $request->validated('keys.p256dh'),
                'auth_token' => (string) $request->validated('keys.auth'),
                'content_encoding' => $request->validated('content_encoding') ?: 'aes128gcm',
                'device_label' => $request->validated('device_label') ?: $this->deviceLabel((string) $request->userAgent()),
                'failures' => 0,
            ]);

            // Tope de dispositivos por persona: se descartan los más antiguos.
            $keep = PushSubscription::query()->where('user_id', $user->id)->latest('updated_at')->latest('id')
                ->limit((int) config('pwa.max_devices', 10))->pluck('id');
            PushSubscription::query()->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();

            return [$subscription, $firstDevice];
        });

        if ($subscription->wasRecentlyCreated) {
            $audit->record('push.suscripcion_creada', $subscription, ['dispositivo' => $subscription->device_label]);
        }

        // Primer dispositivo: los plazos ya programados reciben su aviso push (tras responder).
        if ($firstDevice) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            dispatch(function () use ($reminders, $companyIds) {
                foreach ($companyIds as $companyId) {
                    $reminders->scheduleUpcoming(60, (int) $companyId);
                }
            })->afterResponse();
        }

        return response()->json([
            'message' => 'Este dispositivo recibirá los avisos de plazos.',
            'devices' => PushSubscription::query()->where('user_id', $user->id)->count(),
        ], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']], [
            'endpoint.required' => 'Falta la dirección de la suscripción.',
        ]);

        $subscription = PushSubscription::query()->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))->first();

        // Idempotente: si ya no existe (o era de otra persona y se reasignó), no hay nada que borrar.
        if ($subscription !== null) {
            $this->authorize('delete', $subscription);
            $subscription->delete();
            $audit->record('push.suscripcion_eliminada', $subscription, ['dispositivo' => $subscription->device_label]);
        }

        return response()->json([
            'message' => 'Este dispositivo ya no recibirá avisos.',
            'devices' => PushSubscription::query()->where('user_id', $request->user()->id)->count(),
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $this->authorize('create', PushSubscription::class);

        if (! $this->push->enabled()) {
            return $this->disabled();
        }

        $result = $this->push->sendToUser($request->user(), [
            'title' => 'Aviso de prueba · CRM B2G',
            'body' => 'Así le llegarán los recordatorios de plazos en este dispositivo.',
            'url' => '/mi-cuenta',
            'tag' => 'prueba',
        ]);

        if ($result['sent'] === 0) {
            return response()->json(['message' => 'Ningún dispositivo recibió el aviso. Vuelva a activar los avisos en este dispositivo.', 'result' => $result], 422);
        }

        return response()->json(['message' => $result['sent'] === 1 ? 'Aviso de prueba enviado.' : "Aviso de prueba enviado a {$result['sent']} dispositivos.", 'result' => $result]);
    }

    protected function disabled(): JsonResponse
    {
        return response()->json(['message' => 'Las notificaciones push no están configuradas en el servidor.'], 409);
    }

    /** «Chrome en Android», «Safari en iPhone»… a partir del agente de usuario. */
    protected function deviceLabel(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Navegador',
        };
        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'otro equipo',
        };

        return "{$browser} en {$system}";
    }
}
