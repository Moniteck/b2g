<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\NotificationTemplate;
use App\Models\ReminderDelivery;
use App\Services\Notifications\NotificationFeed;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Campana de la cabecera, «Mis recordatorios» y plantillas de notificación.
 */
class NotificationController extends Controller
{
    public function __construct(protected NotificationFeed $feed, protected CompanyContext $context) {}

    /**
     * «Mis recordatorios»: historial de avisos de la campana del usuario en la empresa activa.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Event::class);

        $filters = $request->validate([
            'estado' => ['nullable', Rule::in(['todos', 'no-leidos', 'leidos'])],
            'tipo' => ['nullable', Rule::in(array_keys(Event::TYPES))],
        ]);
        $status = $filters['estado'] ?? 'todos';
        $type = $filters['tipo'] ?? null;
        $userId = $request->user()->id;
        $companyId = (int) $this->context->currentCompanyId();

        $paginator = $this->feed->query($userId, $companyId)
            ->when($status === 'no-leidos', fn ($query) => $query->whereNull('read_at'))
            ->when($status === 'leidos', fn ($query) => $query->whereNotNull('read_at'))
            ->when($type !== null, fn ($query) => $query->whereHas('event', fn ($inner) => $inner->withoutGlobalScopes()->where('type', $type)))
            ->with(['event' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'title', 'label', 'type', 'starts_at', 'all_day', 'reference_type', 'reference_id', 'contract_id'])])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate(25, ['id', 'event_id', 'offset_key', 'title', 'body', 'href', 'due_at', 'sent_at', 'read_at', 'created_at'])
            ->withQueryString()
            ->through(fn (ReminderDelivery $delivery) => [
                ...$this->feed->present($delivery),
                'event_title' => $delivery->event?->title ?? $delivery->title,
                'type_label' => $delivery->event?->typeLabel() ?? 'Aviso',
                'due_at' => ($delivery->due_at ?? $delivery->event?->starts_at)?->toDateTimeString(),
                'all_day' => (bool) $delivery->event?->all_day,
            ]);

        return Inertia::render('Notifications/Index', [
            'reminders' => $paginator,
            'filters' => ['estado' => $status, 'tipo' => $type],
            'types' => collect(Event::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'unread' => $this->feed->query($userId, $companyId)->whereNull('read_at')->count(),
            'can' => ['manage_templates' => Gate::allows(Permissions::gate(Permissions::MANAGE_SETTINGS))],
        ]);
    }

    /** Lo llama la campana con `fetch`: responde 204 (o JSON si se pide). */
    public function read(Request $request, int $delivery): HttpResponse|JsonResponse|RedirectResponse
    {
        $companyId = $this->context->currentCompanyId();
        abort_if($companyId === null, 404);

        $found = $this->feed->markRead($request->user()->id, $companyId, $delivery);
        abort_unless($found, 404, 'El aviso no existe o no es tuyo.');

        if ($request->header('X-Inertia')) {
            return back();
        }

        return response()->noContent();
    }

    public function readAll(Request $request): HttpResponse|RedirectResponse
    {
        $companyId = $this->context->currentCompanyId();
        $count = $companyId === null ? 0 : $this->feed->markAllRead($request->user()->id, $companyId);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->noContent();
        }

        return back()->with('success', $count > 0 ? 'Avisos marcados como leídos.' : 'No tenías avisos sin leer.');
    }

    /* Plantillas de notificación (solo administradores) */

    public function templates(): Response
    {
        Gate::authorize(Permissions::gate(Permissions::MANAGE_SETTINGS));

        return Inertia::render('Notifications/Templates', [
            'templates' => NotificationTemplate::query()->latest()->paginate(25, ['id', 'name', 'channel', 'subject', 'body', 'active', 'updated_at']),
            'channels' => [['value' => 'correo', 'label' => 'Correo'], ['value' => 'campana', 'label' => 'Campana']],
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        Gate::authorize(Permissions::gate(Permissions::MANAGE_SETTINGS));
        NotificationTemplate::create($this->validateTemplate($request) + ['company_id' => $this->context->currentCompanyId()]);

        return back()->with('success', 'Plantilla creada.');
    }

    public function updateTemplate(Request $request, NotificationTemplate $template): RedirectResponse
    {
        Gate::authorize(Permissions::gate(Permissions::MANAGE_SETTINGS));
        $template->update($this->validateTemplate($request));

        return back()->with('success', 'Plantilla actualizada.');
    }

    public function destroyTemplate(NotificationTemplate $template): RedirectResponse
    {
        Gate::authorize(Permissions::gate(Permissions::MANAGE_SETTINGS));
        $template->delete();

        return back()->with('success', 'Plantilla eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', Rule::in(['correo', 'campana', 'whatsapp'])],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'active' => ['boolean'],
        ], [], ['name' => 'nombre', 'channel' => 'canal', 'subject' => 'asunto', 'body' => 'contenido']);
    }
}
