<?php

namespace App\Services\Notifications;

use App\Jobs\SendReminderEmail;
use App\Models\ReminderDelivery;
use Illuminate\Support\Facades\Log;

/**
 * Despacha los avisos vencidos. Lo llama el scheduler cada minuto.
 *
 * Cada aviso se RECLAMA con una actualización condicional (`Programado` → `Encolado`): si dos
 * procesos corren a la vez, solo uno gana la fila, así que nunca sale dos veces.
 *  - Campana: queda «Enviado» en el acto y aparece en la cabecera del usuario.
 *  - Correo: se encola `SendReminderEmail` (reintentos con espera; al agotarse, «Fallido»).
 */
class ReminderDispatcher
{
    /** Un correo «Encolado» más de este tiempo se considera perdido y se vuelve a programar. */
    public const STALE_MINUTES = 120;

    public function __construct(protected NotificationFeed $feed) {}

    /**
     * @return array{campana: int, correo: int, cancelados: int}
     */
    public function dispatchDue(int $limit = 500): array
    {
        $this->recoverStale();

        $result = ['campana' => 0, 'correo' => 0, 'cancelados' => 0];

        $ids = ReminderDelivery::query()->withoutGlobalScopes()
            ->where('status', 'Programado')
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $claimed = ReminderDelivery::query()->withoutGlobalScopes()
                ->whereKey($id)
                ->where('status', 'Programado')
                ->update(['status' => 'Encolado', 'updated_at' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            $delivery = ReminderDelivery::query()->withoutGlobalScopes()->with(['event' => fn ($query) => $query->withoutGlobalScopes()])->find($id);

            if ($delivery === null) {
                continue;
            }

            if ($delivery->event === null || ! $delivery->event->isActive()) {
                $delivery->forceFill(['status' => 'Cancelado', 'error' => 'El plazo ya no requiere avisos.'])->save();
                $result['cancelados']++;

                continue;
            }

            if ($delivery->channel === 'campana') {
                $delivery->forceFill(['status' => 'Enviado', 'sent_at' => now(), 'attempts' => $delivery->attempts + 1])->save();
                if ($delivery->user_id !== null) {
                    $this->feed->forget($delivery->user_id, $delivery->company_id);
                }
                $result['campana']++;

                continue;
            }

            try {
                SendReminderEmail::dispatch($delivery->id);
                $result['correo']++;
            } catch (\Throwable $exception) {
                // Con la cola síncrona el error del correo sube hasta aquí: ya quedó registrado en la entrega.
                Log::warning('No se pudo encolar o enviar el recordatorio.', ['delivery_id' => $delivery->id, 'error' => $exception->getMessage()]);
            }
        }

        return $result;
    }

    /**
     * Correos encolados cuyo trabajo se perdió (worker caído): vuelven a «Programado».
     */
    protected function recoverStale(): void
    {
        ReminderDelivery::query()->withoutGlobalScopes()
            ->where('status', 'Encolado')
            ->where('channel', 'correo')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => 'Programado', 'updated_at' => now()]);
    }
}
