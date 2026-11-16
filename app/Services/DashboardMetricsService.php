<?php

namespace App\Services;

use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\CollectionInvoice;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\Document;
use App\Models\Event;
use App\Models\Opportunity;
use App\Models\Tender;
use App\Models\User;
use App\Services\Contracts\ContractStatus;
use App\Services\Metrics\BusinessMetrics;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bloques del Dashboard. Las CIFRAS salen de `BusinessMetrics` (la misma definición de Reportes y
 * KPI); aquí se arman la franja «Requiere atención hoy», la línea de plazos de 14 días, lo que vence,
 * la actividad del equipo, las tareas del usuario y la guía de primeros pasos.
 *
 * Presupuesto de consultas (en frío; con caché tibia, ninguna):
 *   inicial   kpis 4 · atención 5 (+1 para administradores)
 *   «charts»  período 5 · mensual 2
 *   «agenda»  plazos 2 · por vencer 3 · actividad 1 · tareas 1 · primeros pasos 1
 */
class DashboardMetricsService
{
    /** Días de la línea de plazos. */
    public const TIMELINE_DAYS = 14;

    /** Días antes del fin de un contrato o de un vencimiento en que se avisa. */
    public const EXPIRING_DAYS = 30;

    /** Tipos de plazo del calendario → categoría de color de la línea de tiempo. */
    private const TIMELINE_CATEGORIES = [
        'Licitacion' => 'tender', 'Aclaraciones' => 'tender', 'Presentacion' => 'tender', 'Apertura' => 'tender', 'Oportunidad' => 'tender',
        'InicioContrato' => 'contract', 'FinContrato' => 'contract', 'Entrega' => 'contract', 'Seguimiento' => 'contract',
        'Documento' => 'document',
        'Manual' => 'other',
    ];

    /** Recursos de la auditoría que cuentan como trabajo del equipo (lo demás es sistema o cuenta). */
    private const ACTIVITY_RESOURCES = [
        'Tender' => ['una licitación', 'gavel'],
        'Opportunity' => ['una oportunidad', 'view-column-outline'],
        'Quote' => ['una cotización', 'file-document-outline'],
        'Contract' => ['un contrato', 'file-sign'],
        'Document' => ['un documento', 'folder-account-outline'],
        'CollectionInvoice' => ['una factura', 'cash-multiple'],
        'Institution' => ['una institución', 'domain'],
        'Client' => ['un cliente', 'account-tie-outline'],
        'Contact' => ['un contacto', 'card-account-details-outline'],
        'Event' => ['un evento', 'calendar-month-outline'],
        'PoaEntry' => ['una partida POA', 'clipboard-list-outline'],
        'KpiGoal' => ['una meta KPI', 'target'],
    ];

    /** Verbo de las acciones más comunes (va seguido del registro); el resto sale del verbo final. */
    private const ACTION_PHRASES = [
        'documento.subido' => 'subió',
        'documento.firmado' => 'firmó',
        'oportunidad.etapa' => 'movió de etapa',
        'cotizacion.estado' => 'cambió el estado de',
        'adjudicacion.ganada' => 'registró como ganada',
        'adjudicacion.perdida' => 'registró como perdida',
        'adjudicacion.revertida' => 'revirtió el resultado de',
        'licitacion.importada' => 'importó',
        'collection.paid' => 'registró el pago de',
        'collection.note' => 'anotó la gestión de cobro de',
        'poa.executed' => 'registró la ejecución de',
        'oferta.participantes' => 'registró los participantes de',
    ];

    private const VERBS = [
        'creada' => 'creó', 'creado' => 'creó', 'created' => 'creó',
        'actualizada' => 'actualizó', 'actualizado' => 'actualizó', 'updated' => 'actualizó',
        'eliminada' => 'eliminó', 'eliminado' => 'eliminó', 'deleted' => 'eliminó',
        'estado' => 'cambió el estado de',
    ];

    /** Claves de `changes` que nombran el registro. */
    private const SUBJECT_KEYS = ['referencia', 'reference', 'cotizacion', 'numero', 'number', 'titulo', 'title', 'nombre', 'name'];

    public function __construct(private BusinessMetrics $metrics, private CompanyContext $context, private AlertService $alerts) {}

    // ───────────────────────────── Período ─────────────────────────────

    /**
     * @return array{preset: string, label: string, from: string, to: string, previous: array{from: string, to: string}, months: array<int, string>}
     */
    public function period(string $preset): array
    {
        $preset = array_key_exists($preset, BusinessMetrics::PERIODS) ? $preset : 'mes';
        $range = BusinessMetrics::presetRange($preset);
        $previous = BusinessMetrics::previousRange($range['from'], $range['to']);

        // Serie de las minigráficas y de «adjudicado frente a cobrado»: 6 meses (12 en el año).
        $end = CarbonImmutable::parse($range['to'])->startOfMonth();
        $count = $preset === 'anio' ? 12 : 6;
        $months = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $months[] = $end->subMonthsNoOverflow($i)->format('Y-m');
        }

        return ['preset' => $preset, 'label' => BusinessMetrics::PERIODS[$preset], ...$range, 'previous' => $previous, 'months' => $months];
    }

    // ───────────────────────────── KPI ─────────────────────────────

    /**
     * Cuatro KPI con su valor, el del período anterior y la minigráfica mensual.
     *
     * @param  array<string, mixed>  $period
     * @return array<int, array<string, mixed>>
     */
    public function kpis(array $period): array
    {
        $seriesFrom = min($period['previous']['from'], $period['months'][0].'-01');
        $series = $this->metrics->kpiSeries($seriesFrom, $period['to']);
        $receivables = $this->metrics->receivables();

        $sum = function (string $from, string $to, string $field) use ($series): float|int {
            $total = 0;
            foreach ($series as $month => $values) {
                if ($month >= substr($from, 0, 7) && $month <= substr($to, 0, 7)) {
                    $total += $values[$field];
                }
            }

            return $total;
        };
        $spark = fn (callable $value) => array_map(fn (string $month) => $value($series[$month] ?? null), $period['months']);

        [$from, $to] = [$period['from'], $period['to']];
        [$prevFrom, $prevTo] = [$period['previous']['from'], $period['previous']['to']];

        $won = (int) $sum($from, $to, 'won');
        $lost = (int) $sum($from, $to, 'lost');
        $pending = (int) $sum($from, $to, 'pending');
        $rate = BusinessMetrics::rate($won, $lost);
        $prevRate = BusinessMetrics::rate((int) $sum($prevFrom, $prevTo, 'won'), (int) $sum($prevFrom, $prevTo, 'lost'));
        $pipeline = round((float) $sum($from, $to, 'pipeline'), 2);
        $wonAmount = round((float) $sum($from, $to, 'won_amount'), 2);
        $previousReceivable = (float) ($series[substr($prevTo, 0, 7)]['receivable'] ?? 0);

        return [
            [
                'key' => 'pipeline',
                'label' => 'Pipeline ponderado',
                'value' => $pipeline,
                'format' => 'currency',
                'previous' => round((float) $sum($prevFrom, $prevTo, 'pipeline'), 2),
                'hint' => $this->plural((int) $sum($from, $to, 'active'), 'oportunidad activa', 'oportunidades activas').' creadas en el período',
                'spark' => $spark(fn ($m) => $m['pipeline'] ?? 0),
                'href' => '/pipeline',
                'featured' => true,
            ],
            [
                'key' => 'award_rate',
                'label' => 'Tasa de adjudicación',
                'value' => $rate,
                'format' => 'percent',
                'previous' => $prevRate,
                'hint' => $this->plural($won, 'ganada', 'ganadas').' · '.$this->plural($lost, 'perdida', 'perdidas').' · '.$this->plural($pending, 'pendiente', 'pendientes'),
                'spark' => $spark(fn ($m) => $m === null ? null : BusinessMetrics::rate($m['won'], $m['lost'])),
                'href' => '/cotizaciones',
            ],
            [
                'key' => 'won_amount',
                'label' => 'Adjudicado en el período',
                'value' => $wonAmount,
                'format' => 'currency',
                'previous' => round((float) $sum($prevFrom, $prevTo, 'won_amount'), 2),
                'hint' => $this->plural($won, 'cotización ganada', 'cotizaciones ganadas'),
                'spark' => $spark(fn ($m) => $m['won_amount'] ?? 0),
                'href' => "/reportes?from={$from}&to={$to}",
            ],
            [
                'key' => 'receivable',
                'label' => 'Cartera por cobrar',
                'value' => $receivables['outstanding'],
                'format' => 'currency',
                'previous' => $previousReceivable,
                'hint' => $receivables['overdue'] > 0
                    ? 'L '.number_format($receivables['overdue'], 2).' vencida ('.$this->plural($receivables['overdue_count'], 'factura', 'facturas').')'
                    : $this->plural($receivables['outstanding_count'], 'factura pendiente', 'facturas pendientes').', ninguna vencida',
                'spark' => $spark(fn ($m) => $m['receivable'] ?? 0),
                'href' => '/cobros',
                'alert' => $receivables['overdue'] > 0,
            ],
        ];
    }

    // ───────────────────────────── Requiere atención hoy ─────────────────────────────

    /**
     * Lo que hay que resolver hoy o mañana, cada ítem con su acción. Vacío = la franja no se muestra.
     *
     * @return array<int, array<string, mixed>>
     */
    public function attention(User $user): array
    {
        return $this->metrics->remember('dashboard.attention', [$user->id, $this->canWrite($user), $user->can(Permissions::gate(Permissions::MANAGE_USERS))], function () use ($user) {
            $write = $this->canWrite($user);
            $now = Carbon::now();
            $tomorrow = $now->copy()->addDay()->endOfDay();
            $items = [];

            $tenders = Tender::query()
                ->whereIn('status', Tender::OPEN_STATUSES)
                ->where(fn ($query) => $query
                    ->whereBetween('clarification_deadline', [$now, $tomorrow])
                    ->orWhere(fn ($inner) => $inner->where('offer_submitted', false)->whereBetween('presentation_deadline', [$now, $now->copy()->addDays(2)->endOfDay()])))
                ->orderBy('presentation_deadline')
                ->limit(8)
                ->get(['id', 'reference', 'title', 'clarification_deadline', 'presentation_deadline', 'offer_submitted']);

            foreach ($tenders as $tender) {
                $href = "/licitaciones/{$tender->id}";
                if ($tender->clarification_deadline?->between($now, $tomorrow)) {
                    $items[] = $this->item("clarification-{$tender->id}", $tender->clarification_deadline->isToday() ? 'error' : 'warning', 'comment-question-outline',
                        "Cierran las aclaraciones de {$tender->reference}", $tender->title, $tender->clarification_deadline, $write ? 'Enviar consultas' : 'Ver licitación', $href);
                }
                if (! $tender->offer_submitted && $tender->presentation_deadline?->between($now, $now->copy()->addDays(2)->endOfDay())) {
                    $items[] = $this->item("offer-{$tender->id}", $tender->presentation_deadline->isToday() ? 'error' : 'warning', 'send-clock-outline',
                        "Oferta por presentar: {$tender->reference}", $tender->title, $tender->presentation_deadline, $write ? 'Preparar oferta' : 'Ver licitación', $href);
                }
            }

            // Documentos vencidos: de las alertas de la cabecera (misma caché, sin otra consulta).
            $expired = collect($this->alerts->active())->where('type', 'documentos')->where('severity', 'danger')->values();
            if ($expired->isNotEmpty()) {
                $first = $expired->first();
                $items[] = $this->item('documents-expired', 'error', 'file-alert-outline',
                    $expired->count() === 1 ? "Documento vencido: {$first['subject']}" : "{$expired->count()} documentos vencidos",
                    $expired->count() === 1 ? 'Venció el '.Carbon::parse($first['date'])->format('d/m/Y') : $expired->take(3)->pluck('subject')->implode(' · '),
                    null, $write ? 'Renovar' : 'Ver documentos', $expired->count() === 1 ? $first['href'] : '/directorio?status=vencido');
            }

            $contracts = Contract::query()->whereIn('status', ContractStatus::OPEN)
                ->whereNotNull('end_date')->whereBetween('end_date', [$now->copy()->startOfDay(), $now->copy()->addDays(7)->endOfDay()])
                ->orderBy('end_date')->limit(5)->get(['id', 'number', 'name', 'end_date']);
            foreach ($contracts as $contract) {
                $items[] = $this->item("contract-{$contract->id}", $contract->end_date->isToday() ? 'error' : 'warning', 'file-sign',
                    "Vence el contrato {$contract->number}", $contract->name, $contract->end_date, $write ? 'Revisar contrato' : 'Ver contrato', "/post-adjudicacion?contrato={$contract->id}", allDay: true);
            }

            $deliveries = ContractDelivery::query()
                ->join('contracts', 'contracts.id', '=', 'contract_deliveries.contract_id')
                ->where('contracts.company_id', $this->context->currentCompanyId())
                ->where('contract_deliveries.status', ContractDelivery::PENDING)
                ->where('contract_deliveries.due_date', '<', $now->copy()->addDay()->endOfDay())
                ->orderBy('contract_deliveries.due_date')
                ->limit(20)
                ->get(['contract_deliveries.id', 'contract_deliveries.description', 'contract_deliveries.due_date', 'contracts.id as contract_id', 'contracts.number']);
            if ($deliveries->isNotEmpty()) {
                $late = $deliveries->filter(fn ($delivery) => Carbon::parse($delivery->due_date)->lt($now->copy()->startOfDay()))->count();
                $first = $deliveries->first();
                $items[] = $this->item('deliveries', $late > 0 ? 'error' : 'warning', 'truck-delivery-outline',
                    $late > 0 ? $this->plural($late, 'entrega atrasada', 'entregas atrasadas') : $this->plural($deliveries->count(), 'entrega vence hoy o mañana', 'entregas vencen hoy o mañana'),
                    "{$first->number}: {$first->description}", null, $write ? 'Registrar entrega' : 'Ver contrato', "/post-adjudicacion?contrato={$first->contract_id}");
            }

            $receivables = $this->metrics->receivables();
            if ($receivables['overdue_count'] > 0) {
                $items[] = $this->item('receivables', 'error', 'cash-clock',
                    $this->plural($receivables['overdue_count'], 'factura en mora', 'facturas en mora'),
                    'L '.number_format($receivables['overdue'], 2).' vencidos por cobrar', null, $write ? 'Gestionar cobro' : 'Ver cobros', '/cobros');
            }

            if ($user->can(Permissions::gate(Permissions::MANAGE_USERS))) {
                $requests = AccessRequest::query()->pending()->verified()
                    ->where(fn ($query) => $query->where('company_id', $this->context->currentCompanyId())->when($user->isRoot(), fn ($inner) => $inner->orWhereNull('company_id')))
                    ->count();
                if ($requests > 0) {
                    $items[] = $this->item('access-requests', 'info', 'account-clock-outline',
                        $this->plural($requests, 'solicitud de acceso pendiente', 'solicitudes de acceso pendientes'), 'Personas que esperan entrar a la empresa', null, 'Revisar', '/configuracion/usuarios');
                }
            }

            $order = ['error' => 0, 'warning' => 1, 'info' => 2];

            return collect($items)->sortBy([fn ($a, $b) => $order[$a['tone']] <=> $order[$b['tone']], fn ($a, $b) => strcmp((string) $a['due_at'], (string) $b['due_at'])])->values()->all();
        });
    }

    // ───────────────────────────── Gráficas ─────────────────────────────

    /**
     * Embudo por etapa del período y adjudicado frente a cobrado por mes.
     *
     * @param  array<string, mixed>  $period
     * @return array{stages: array<int, array<string, mixed>>, monthly: array<int, array<string, mixed>>, open_tenders: int}
     */
    public function charts(array $period): array
    {
        $pipeline = $this->metrics->period($period['from'], $period['to'])['pipeline'];
        $first = $period['months'][0].'-01';
        $last = CarbonImmutable::parse(end($period['months']).'-01')->endOfMonth()->toDateString();

        return [
            'stages' => array_map(fn (array $stage) => $stage + [
                'active' => in_array($stage['stage'], Opportunity::ACTIVE_STAGES, true),
                'href' => '/pipeline?etapa='.$stage['stage'],
            ], $pipeline['stages']),
            'monthly' => $this->metrics->monthly($first, $last),
            'open_tenders' => $this->metrics->openTenders(),
        ];
    }

    // ───────────────────────────── Agenda ─────────────────────────────

    /**
     * Plazos, vencimientos, actividad, tareas y primeros pasos (prop diferida «agenda»).
     *
     * @return array<string, mixed>
     */
    public function agenda(User $user): array
    {
        return $this->metrics->remember('dashboard.agenda', [$user->id, $this->canWrite($user)], fn () => [
            'timeline' => $this->timeline(),
            'expiring' => $this->expiring(),
            'activity' => $this->activity(),
            'tasks' => $this->tasks($user),
            'onboarding' => $this->onboarding($user),
        ]);
    }

    /**
     * Plazos de los próximos 14 días: los del calendario (licitaciones, contratos, entregas,
     * documentos, manuales) y los vencimientos de facturas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function timeline(): array
    {
        $from = Carbon::now()->startOfDay();
        $until = Carbon::now()->addDays(self::TIMELINE_DAYS - 1)->endOfDay();

        $events = Event::query()->pending()
            ->whereBetween('starts_at', [$from, $until])
            ->orderBy('starts_at')
            ->limit(60)
            ->get(['id', 'title', 'label', 'type', 'starts_at', 'all_day', 'reference_type', 'reference_id', 'contract_id'])
            ->map(fn (Event $event) => [
                'id' => "event-{$event->id}",
                'title' => $event->label ?: $event->title,
                'type' => $event->typeLabel(),
                'category' => self::TIMELINE_CATEGORIES[$event->type] ?? 'other',
                'at' => $event->starts_at->toDateTimeString(),
                'all_day' => (bool) $event->all_day,
                'href' => $event->sourceHref(),
            ]);

        $invoices = CollectionInvoice::query()
            ->where('status', '!=', CollectionInvoice::STATUS_PAID)
            ->whereBetween('due_at', [$from->toDateString(), $until->toDateString()])
            ->orderBy('due_at')
            ->limit(20)
            ->get(['id', 'number', 'concept', 'due_at'])
            ->map(fn (CollectionInvoice $invoice) => [
                'id' => "invoice-{$invoice->id}",
                'title' => "Factura {$invoice->number}",
                'type' => 'Vencimiento de factura',
                'category' => 'collection',
                'at' => $invoice->due_at->toDateString().' 00:00:00',
                'all_day' => true,
                'href' => '/cobros',
            ]);

        return $events->concat($invoices)->sortBy('at')->values()->all();
    }

    /**
     * Contratos que terminan (o ya terminaron sin cerrarse) y documentos vencidos o por vencer.
     *
     * @return array{contracts: array<int, array<string, mixed>>, documents: array<int, array<string, mixed>>, counts: array{contracts: int, documents: int}}
     */
    private function expiring(): array
    {
        $today = CarbonImmutable::today();
        $limit = $today->addDays(self::EXPIRING_DAYS);

        $contracts = Contract::query()->whereIn('status', ContractStatus::OPEN)
            ->whereNotNull('end_date')->where('end_date', '<=', $limit->toDateString())
            ->orderBy('end_date')->limit(6)
            ->get(['id', 'number', 'name', 'end_date', 'status', 'physical_progress'])
            ->map(fn (Contract $contract) => [
                'id' => $contract->id,
                'title' => $contract->number,
                'detail' => $contract->name,
                'date' => $contract->end_date->toDateString(),
                'progress' => $contract->physical_progress === null ? null : (float) $contract->physical_progress,
                'status' => $contract->status,
                'href' => "/post-adjudicacion?contrato={$contract->id}",
            ]);

        $documents = Document::query()->whereNotNull('expires_at')->where('expires_at', '<=', $limit->toDateString())
            ->orderBy('expires_at')->limit(6)
            ->get(['id', 'name', 'expires_at', 'category'])
            ->map(fn (Document $document) => [
                'id' => $document->id,
                'title' => $document->name,
                'detail' => Document::CATEGORIES[$document->category] ?? null,
                'date' => $document->expires_at->toDateString(),
                'href' => "/directorio?documento={$document->id}",
            ]);

        $counts = [
            'contracts' => $contracts->count(),
            'documents' => $documents->count(),
        ];

        return ['contracts' => $contracts->all(), 'documents' => $documents->all(), 'counts' => $counts];
    }

    /**
     * Últimos cambios del equipo en registros del negocio (de la auditoría; sin datos técnicos).
     *
     * @return array<int, array<string, mixed>>
     */
    private function activity(): array
    {
        return AuditLog::query()
            ->whereIn('resource_type', array_keys(self::ACTIVITY_RESOURCES))
            ->whereNotNull('actor_name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'actor_name', 'action', 'resource_type', 'resource_id', 'changes', 'created_at'])
            ->map(function (AuditLog $log) {
                [$noun, $icon] = self::ACTIVITY_RESOURCES[$log->resource_type];
                $changes = is_array($log->changes) ? $log->changes : (json_decode((string) $log->changes, true) ?: []);
                $subject = collect(self::SUBJECT_KEYS)->map(fn ($key) => $changes[$key] ?? null)->first(fn ($value) => is_string($value) && $value !== '');

                return [
                    'id' => $log->id,
                    'actor' => $log->actor_name,
                    // «creó la cotización COT-1» con nombre; «movió de etapa una oportunidad» sin él.
                    'phrase' => trim($this->phrase($log->action).' '.($subject ? preg_replace(['/^una /u', '/^un /u'], ['la ', 'el '], $noun) : $noun)),
                    'subject' => $subject,
                    'icon' => $icon,
                    'at' => $log->created_at?->toDateTimeString(),
                    'href' => $this->resourceHref($log->resource_type, $log->resource_id, $log->action),
                ];
            })
            ->all();
    }

    /**
     * Recordatorios pendientes del usuario (dueño del plazo): atrasados de la última semana y los de
     * los próximos 14 días.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tasks(User $user): array
    {
        return Event::query()->pending()
            ->where(fn ($query) => $query->where('owner_id', $user->id)->orWhere(fn ($inner) => $inner->whereNull('owner_id')->where('user_id', $user->id)))
            ->whereBetween('starts_at', [Carbon::now()->subDays(7)->startOfDay(), Carbon::now()->addDays(self::TIMELINE_DAYS)->endOfDay()])
            ->orderBy('starts_at')
            ->limit(6)
            ->get(['id', 'title', 'label', 'type', 'starts_at', 'all_day', 'reference_type', 'reference_id', 'contract_id'])
            ->map(fn (Event $event) => [
                'id' => $event->id,
                'title' => $event->label ?: $event->title,
                'type' => $event->typeLabel(),
                'at' => $event->starts_at->toDateTimeString(),
                'all_day' => (bool) $event->all_day,
                'overdue' => $event->starts_at->lt(Carbon::now()),
                'href' => $event->sourceHref(),
            ])
            ->all();
    }

    /**
     * Guía de primeros pasos para una empresa nueva. Se muestra mientras la empresa tenga pocos
     * registros y falte algún paso.
     *
     * @return array{show: bool, steps: array<int, array<string, mixed>>}
     */
    private function onboarding(User $user): array
    {
        $companyId = (int) $this->context->currentCompanyId();
        $row = DB::selectOne(
            'SELECT
                (SELECT COUNT(*) FROM tenders WHERE company_id = ?) AS tenders,
                (SELECT COUNT(*) FROM quotes WHERE company_id = ? AND deleted_at IS NULL) AS quotes,
                (SELECT COUNT(*) FROM opportunities WHERE company_id = ?) AS opportunities,
                (SELECT COUNT(*) FROM documents WHERE company_id = ?) AS documents,
                (SELECT COUNT(*) FROM company_user WHERE company_id = ?) AS members',
            [$companyId, $companyId, $companyId, $companyId, $companyId],
        );

        $write = $this->canWrite($user);
        $admin = $user->can(Permissions::gate(Permissions::MANAGE_USERS));
        $steps = [
            ['key' => 'tender', 'title' => 'Importa tu primera licitación', 'detail' => 'Sube la captura de la ficha de HonduCompras y el sistema la lee por ti.', 'icon' => 'text-recognition', 'done' => $row->tenders > 0, 'action' => $write ? 'Importar captura' : null, 'href' => '/licitaciones'],
            ['key' => 'documents', 'title' => 'Carga los documentos de la empresa', 'detail' => 'RTN, constancias y solvencias en el Directorio, con su vencimiento.', 'icon' => 'folder-upload-outline', 'done' => $row->documents > 0, 'action' => $write ? 'Ir al directorio' : null, 'href' => '/directorio'],
            ['key' => 'team', 'title' => 'Invita a tu equipo', 'detail' => 'Agrega a quienes preparan ofertas y dan seguimiento.', 'icon' => 'account-multiple-plus-outline', 'done' => $row->members > 1, 'action' => $admin ? 'Invitar' : null, 'href' => '/configuracion/usuarios'],
            ['key' => 'quote', 'title' => 'Crea tu primera cotización', 'detail' => 'Desde la licitación o desde cero, con su PDF firmado.', 'icon' => 'file-document-plus-outline', 'done' => $row->quotes > 0, 'action' => $write ? 'Nueva cotización' : null, 'href' => '/cotizaciones'],
        ];

        $records = $row->tenders + $row->quotes + $row->opportunities + $row->documents;
        $pending = collect($steps)->where('done', false)->count();

        return ['show' => $pending > 0 && $records < 10, 'steps' => $steps];
    }

    // ───────────────────────────── MCP ─────────────────────────────

    /**
     * Resumen para la herramienta MCP `get_dashboard` (mes en curso), con las definiciones únicas.
     *
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        $range = BusinessMetrics::presetRange('mes');
        $period = $this->metrics->period($range['from'], $range['to']);
        $receivables = $this->metrics->receivables();
        $history = $this->metrics->awardRate();

        return [
            'period' => $range,
            'pipeline' => $period['pipeline']['weighted'],
            'opportunities' => $period['pipeline']['active_count'],
            'awardRate' => $period['quotes']['award_rate'],
            'wonQuotes' => $period['quotes']['won_count'],
            'lostQuotes' => $period['quotes']['lost_count'],
            'pendingQuotes' => $period['quotes']['pending_count'],
            'wonAmount' => $period['quotes']['won_amount'],
            'awardRateAllTime' => $history['rate'],
            'openTenders' => $this->metrics->openTenders(),
            'receivable' => $receivables['outstanding'],
            'overdueReceivables' => $receivables['overdue'],
        ];
    }

    // ───────────────────────────── Ayudas ─────────────────────────────

    private function canWrite(User $user): bool
    {
        return $user->can(Permissions::gate(Permissions::UPDATE));
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $id, string $tone, string $icon, string $title, ?string $detail, ?\DateTimeInterface $due, string $action, string $href, bool $allDay = false): array
    {
        return [
            'id' => $id,
            'tone' => $tone,
            'icon' => $icon,
            'title' => $title,
            'detail' => $detail,
            'due_at' => $due ? Carbon::instance($due)->toDateTimeString() : null,
            'all_day' => $allDay,
            'action' => ['label' => $action, 'href' => $href],
        ];
    }

    private function plural(int $count, string $one, string $many): string
    {
        return $count === 1 ? "1 {$one}" : number_format($count)." {$many}";
    }

    private function phrase(string $action): string
    {
        return self::ACTION_PHRASES[$action] ?? self::VERBS[substr(strrchr($action, '.') ?: $action, 1)] ?? 'actualizó';
    }

    private function resourceHref(string $type, ?int $id, string $action): ?string
    {
        if ($id === null || str_contains($action, 'elimin') || str_contains($action, 'deleted')) {
            return null;
        }

        return match ($type) {
            'Tender' => "/licitaciones/{$id}",
            'Opportunity' => "/pipeline/{$id}",
            'Quote' => "/cotizaciones?ver={$id}",
            'Contract' => "/post-adjudicacion?contrato={$id}",
            'Document' => "/directorio?documento={$id}",
            'CollectionInvoice' => '/cobros',
            'Institution' => "/instituciones/{$id}",
            'Client' => "/clientes/{$id}",
            default => null,
        };
    }
}
