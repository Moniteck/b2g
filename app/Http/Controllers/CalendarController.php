<?php

namespace App\Http\Controllers;

use App\Http\Requests\Calendar\EventRequest;
use App\Http\Requests\Calendar\ReminderRulesRequest;
use App\Models\Event;
use App\Models\ReminderDelivery;
use App\Models\ReminderRule;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\CalendarEventService;
use App\Services\Notifications\ReminderService;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendario de plazos: rejilla mensual y agenda, detalle de evento, eventos manuales,
 * «Marcar como atendido» y reglas de recordatorio de la empresa.
 */
class CalendarController extends Controller
{
    /** Rango máximo que puede pedir la vista (un mes con sus semanas de borde, con holgura). */
    public const MAX_RANGE_DAYS = 100;

    public function __construct(
        protected CompanyContext $context,
        protected ReminderService $reminders,
        protected AuditLogger $audit,
        protected CalendarEventService $events,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Event::class);

        $user = $request->user();
        $canManageRules = Gate::allows(Permissions::gate(Permissions::MANAGE_SETTINGS));

        return Inertia::render('Calendar/Index', [
            'types' => collect(Event::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'defaults' => collect(Event::TYPES)->keys()->mapWithKeys(fn ($type) => [$type => $this->reminders->rulesFor($this->context->currentCompanyId(), $type)['days_before']]),
            'members' => fn () => $user->can('create', Event::class) ? $this->members() : [],
            'rules' => fn () => $canManageRules ? $this->rules() : null,
            'initial' => [
                'month' => $this->monthParam($request->query('mes')),
                'event' => $request->integer('evento') ?: null,
            ],
            'can' => [
                'create' => $user->can('create', Event::class),
                'update' => $user->hasPermission(Permissions::UPDATE),
                'delete' => $user->hasPermission(Permissions::DELETE),
                'manage_rules' => $canManageRules,
            ],
        ]);
    }

    /**
     * Eventos visibles en un rango: los que empiezan dentro o lo cruzan (barras de varios días).
     */
    public function events(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Event::class);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'types' => ['nullable', 'array'],
            'types.*' => ['string'],
        ], ['to.after_or_equal' => 'El fin del rango no puede ser anterior al inicio.']);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'El rango no puede superar '.self::MAX_RANGE_DAYS.' días.']);
        }

        $events = Event::query()
            ->visible()
            ->where('starts_at', '<=', $to)
            ->where(fn ($query) => $query
                ->where('starts_at', '>=', $from)
                ->orWhere(fn ($inner) => $inner->whereNotNull('ends_at')->where('ends_at', '>=', $from)))
            ->when(! empty($data['types']), fn ($query) => $query->whereIn('type', $data['types']))
            ->orderBy('starts_at')
            ->limit(1000)
            ->get([
                'id', 'title', 'label', 'type', 'starts_at', 'ends_at', 'all_day', 'origin', 'priority', 'reference_type',
                'reference_id', 'tender_id', 'contract_id', 'completed', 'attended_at',
            ]);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'events' => $events->map(fn (Event $event) => $this->summary($event))->all(),
        ]);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $event->load([
            'owner:id,name', 'creator:id,name', 'attendedBy:id,name',
            'deliveries' => fn ($query) => $query->withoutGlobalScopes()->with('user:id,name')->orderBy('scheduled_for'),
        ]);

        $user = $request->user();
        $deliveries = $event->deliveries->map(fn (ReminderDelivery $delivery) => [
            'id' => $delivery->id,
            'offset' => $delivery->offsetLabel(),
            'channel' => ReminderDelivery::CHANNELS[$delivery->channel] ?? $delivery->channel,
            'recipient' => $delivery->user?->name ?? $delivery->recipient,
            'status' => $delivery->status,
            'scheduled_for' => $delivery->scheduled_for?->toDateTimeString(),
            'sent_at' => $delivery->sent_at?->toDateTimeString(),
            'error' => $delivery->status === 'Fallido' ? $delivery->error : null,
        ]);

        return response()->json([
            'event' => [
                ...$this->summary($event),
                'description' => $event->description,
                'source_label' => $event->sourceLabel(),
                'owner' => $event->owner?->name,
                'creator' => $event->creator?->name,
                'attended_by' => $event->attendedBy?->name,
                'attended_at' => $event->attended_at?->toDateTimeString(),
                'cancelled' => $event->cancelled_at !== null,
                'reminders' => $event->reminder_offsets,
                'recipients' => array_values(array_map('intval', array_filter((array) ($event->recipients ?? []), 'is_numeric'))),
                'rule_days' => $this->reminders->rulesFor($event->company_id, (string) $event->type)['days_before'],
            ],
            'deliveries' => [
                'scheduled' => $deliveries->whereIn('status', ReminderDelivery::PENDING)->values(),
                'history' => $deliveries->whereNotIn('status', ReminderDelivery::PENDING)->values(),
            ],
            'can' => [
                'attend' => $user->can('update', $event) && $event->attended_at === null && $event->cancelled_at === null,
                'reopen' => $user->can('update', $event) && $event->attended_at !== null,
                'update' => $event->isManual() && $user->can('update', $event),
                'delete' => $event->isManual() && $this->canDelete($user, $event),
            ],
        ]);
    }

    public function store(EventRequest $request): RedirectResponse
    {
        $this->authorize('create', Event::class);

        $this->events->create($this->events->attributes($request->validated()), $request->user(), (int) $this->context->currentCompanyId());

        return back()->with('success', 'Evento creado. Sus recordatorios quedaron programados.');
    }

    public function update(EventRequest $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);
        $this->ensureManual($event);

        $this->events->update($event, $this->events->attributes($request->validated()), $request->user());

        return back()->with('success', 'Evento actualizado. Los recordatorios pendientes se reprogramaron.');
    }

    public function destroy(Request $request, Event $event): RedirectResponse
    {
        $this->ensureManual($event);
        abort_unless($this->canDelete($request->user(), $event), 403);

        $this->events->delete($event, $request->user());

        return back()->with('success', 'Evento eliminado.');
    }

    /**
     * «Marcar como atendido»: detiene los avisos de este plazo para todos y guarda quién y cuándo.
     */
    public function attend(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $done = $this->events->attend($event, $request->user());

        return back()->with('success', $done ? 'Plazo marcado como atendido. Ya no enviará avisos.' : 'Este plazo ya estaba atendido.');
    }

    public function reopen(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $this->events->reopen($event, $request->user());

        return back()->with('success', 'El plazo vuelve a estar pendiente y sus avisos se reprogramaron.');
    }

    public function saveRules(ReminderRulesRequest $request): RedirectResponse
    {
        Gate::authorize(Permissions::gate(Permissions::MANAGE_SETTINGS));

        $companyId = $this->context->currentCompanyId();
        abort_if($companyId === null, 422, 'No hay una empresa activa.');

        DB::transaction(function () use ($request, $companyId) {
            foreach ($request->validated('rules') as $rule) {
                ReminderRule::query()->withoutGlobalScopes()->updateOrCreate(
                    ['company_id' => $companyId, 'event_type' => $rule['event_type']],
                    [
                        'days_before' => ReminderService::normalizeDays($rule['days_before']),
                        'send_time' => $rule['send_time'],
                        'channels' => array_values(array_unique($rule['channels'])),
                        'recipients' => array_values(array_unique(array_map('strtolower', $rule['recipients']))),
                        'is_active' => $rule['is_active'] ?? true,
                    ],
                );
            }
        });

        $this->reminders->forgetRules();
        $replanned = $this->reminders->scheduleUpcoming(60, $companyId);
        $this->audit->record('calendar.rules.updated', null, ['tipos' => collect($request->validated('rules'))->pluck('event_type')->all()], companyId: $companyId);

        return back()->with('success', "Reglas guardadas. Se reprogramaron los avisos pendientes ({$replanned} nuevos).");
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'label' => $event->label,
            'type' => $event->type,
            'type_label' => $event->typeLabel(),
            'starts_at' => $event->starts_at?->toDateTimeString(),
            'ends_at' => $event->ends_at?->toDateTimeString(),
            'all_day' => (bool) $event->all_day,
            'origin' => $event->origin,
            'manual' => $event->isManual(),
            'priority' => $event->priority,
            'tender_id' => $event->tender_id,
            'contract_id' => $event->contract_id,
            'href' => $event->isManual() ? null : $event->sourceHref(),
            'attended' => $event->attended_at !== null,
            'completed' => (bool) $event->completed,
        ];
    }

    protected function ensureManual(Event $event): void
    {
        if ($event->isDerived()) {
            throw ValidationException::withMessages(['event' => 'Este plazo viene de su registro de origen: se cambia desde allí.']);
        }
    }

    protected function canDelete(User $user, Event $event): bool
    {
        return $user->can('delete', $event)
            || ((int) $event->created_by === $user->id && $user->can('update', $event));
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    protected function members(): array
    {
        $companyId = $this->context->currentCompanyId();

        return User::query()
            ->join('company_user', 'company_user.user_id', '=', 'users.id')
            ->where('company_user.company_id', $companyId)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rules(): array
    {
        $companyId = $this->context->currentCompanyId();

        return collect(Event::TYPES)->map(function (string $label, string $type) use ($companyId) {
            $rule = $this->reminders->rulesFor($companyId, $type);
            $stored = $companyId === null ? null : $this->reminders->storedRules($companyId)->get($type);

            return [
                'event_type' => $type,
                'label' => $label,
                'days_before' => $stored && ! $stored->is_active ? [] : $rule['days_before'],
                'send_time' => $rule['send_time'],
                'channels' => $rule['channels'],
                'recipients' => $rule['recipients'],
                'is_active' => $stored?->is_active ?? true,
                'custom' => $rule['custom'],
            ];
        })->values()->all();
    }

    protected function monthParam(?string $month): string
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : now()->format('Y-m');
    }
}
