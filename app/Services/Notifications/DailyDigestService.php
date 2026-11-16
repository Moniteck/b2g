<?php

namespace App\Services\Notifications;

use App\Mail\DailyDigestMail;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Resumen diario por correo (7:00 a. m., hora de Honduras).
 *
 * A cada miembro de cada empresa le llegan los plazos pendientes de hoy y de los próximos 7 días
 * de los que es responsable, creador o destinatario; los administradores reciben todos los de la
 * empresa. Si no hay nada, no se envía. Una vez por usuario, empresa y día (aunque se reejecute).
 */
class DailyDigestService
{
    public const DAYS = 7;

    /**
     * @return int Correos enviados.
     */
    public function sendAll(?Carbon $today = null): int
    {
        $sent = 0;

        Company::query()->orderBy('id')->pluck('id')->each(function ($companyId) use (&$sent, $today) {
            $sent += $this->sendForCompany((int) $companyId, $today);
        });

        return $sent;
    }

    public function sendForCompany(int $companyId, ?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $events = $this->eventsFor($companyId, $today);

        if ($events->isEmpty()) {
            return 0;
        }

        $companyName = Company::query()->whereKey($companyId)->value('name');
        $members = DB::table('company_user')->where('company_id', $companyId)->pluck('role', 'user_id');
        $users = User::query()->whereIn('id', $members->keys())->get(['id', 'name', 'email']);
        $sent = 0;

        foreach ($users as $user) {
            $isAdmin = ($members[$user->id] ?? null) === Permissions::ROLE_ADMIN;
            $mine = $isAdmin ? $events : $events->filter(fn (Event $event) => $this->concerns($event, $user->id));

            if ($mine->isEmpty() || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Una sola vez por día aunque el scheduler o una persona lo reejecuten.
            if (! Cache::add("digest.{$companyId}.{$user->id}.{$today->toDateString()}", true, now()->addDays(2))) {
                continue;
            }

            try {
                // En cola: el programador no espera al SMTP usuario por usuario.
                Mail::to($user->email)->queue(new DailyDigestMail(
                    userName: $user->name,
                    companyName: $companyName,
                    groups: $this->groups($mine, $today),
                    total: $mine->count(),
                    dateLabel: $today->format('d/m/Y'),
                ));
                $sent++;
            } catch (Throwable $exception) {
                Cache::forget("digest.{$companyId}.{$user->id}.{$today->toDateString()}");
                Log::warning('No se pudo enviar el resumen diario.', ['company_id' => $companyId, 'user_id' => $user->id, 'error' => $exception->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * @return Collection<int, Event>
     */
    public function eventsFor(int $companyId, Carbon $today): Collection
    {
        return Event::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pending()
            ->whereBetween('starts_at', [$today, $today->copy()->addDays(self::DAYS)->endOfDay()])
            ->orderBy('starts_at')
            ->get(['id', 'company_id', 'title', 'label', 'type', 'starts_at', 'all_day', 'owner_id', 'created_by', 'user_id', 'recipients', 'reference_type', 'reference_id', 'contract_id']);
    }

    protected function concerns(Event $event, int $userId): bool
    {
        $chosen = array_map('intval', array_filter((array) ($event->recipients ?? []), 'is_numeric'));

        return in_array($userId, [(int) $event->owner_id, (int) $event->created_by, (int) $event->user_id], true)
            || in_array($userId, $chosen, true);
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return array<int, array{label: string, today: bool, items: array<int, array<string, string>>}>
     */
    protected function groups(Collection $events, Carbon $today): array
    {
        return $events
            ->groupBy(fn (Event $event) => $event->starts_at->toDateString())
            ->map(function (Collection $items, string $day) use ($today) {
                $date = Carbon::parse($day);
                $diff = (int) $today->diffInDays($date, false);

                return [
                    'label' => match ($diff) {
                        0 => 'Hoy',
                        1 => 'Mañana',
                        default => ucfirst($date->locale('es')->isoFormat('dddd D [de] MMMM')),
                    },
                    'today' => $diff === 0,
                    'items' => $items->map(fn (Event $event) => [
                        'title' => $event->title,
                        'type' => $event->typeLabel(),
                        'when' => $event->all_day ? 'Todo el día' : NotificationFeed::time($event->starts_at),
                        'url' => url($event->sourceHref()),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }
}
