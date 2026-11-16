<?php

namespace App\Services\Tenders;

use App\Models\Institution;
use App\Models\Tender;
use App\Models\User;
use App\Services\Metrics\BusinessMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Lista de Licitaciones: filtros, orden, cifras de cabecera y las tres vistas (tabla, kanban por
 * etapa y calendario de plazos). La usan la pantalla y los exportes con los mismos filtros.
 *
 *   $list = app(TenderListQuery::class);
 *   $filters = $list->filters($request);
 *   $list->filtered($filters, $user)->paginate(25);
 */
class TenderListQuery
{
    public const VIEWS = ['tabla', 'kanban', 'calendario'];

    /** Atajos de plazo de las cifras de cabecera (los usan el Dashboard y la MCP). */
    public const WINDOWS = ['all', 'open', 'week', 'clarifications', 'presented'];

    public const SORTS = ['deadline', 'reference', 'amount', 'stage', 'institution', 'owner', 'created'];

    /** Tarjetas por columna del kanban (el resto se ve en la tabla con el filtro de etapa). */
    public const BOARD_LIMIT = 40;

    public function __construct(protected BusinessMetrics $metrics) {}

    /**
     * @return array<string, mixed>
     */
    public function filters(Request $request): array
    {
        $stages = array_values(array_intersect(
            array_filter(explode(',', (string) $request->query('etapa', ''))),
            array_keys(TenderStage::STAGES),
        ));
        $window = (string) $request->query('window', 'all');
        $view = (string) $request->query('vista', 'tabla');
        $sort = (string) $request->query('orden', 'deadline');
        $month = (string) $request->query('mes', '');

        return [
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 100),
            'stages' => $stages,
            // Compatibilidad: `?status=Cancelada` (enlaces viejos, MCP) sigue filtrando por estado guardado.
            'status' => in_array($request->query('status'), Tender::STATUSES, true) ? $request->query('status') : '',
            'modality' => mb_substr(trim((string) $request->query('modality', '')), 0, 120),
            'institution_id' => $request->integer('institution_id') ?: null,
            'owner_id' => $request->integer('owner_id') ?: null,
            'mine' => $request->boolean('mias'),
            'from' => self::date($request->query('desde')),
            'to' => self::date($request->query('hasta')),
            // Las vencidas sin oferta se ocultan por defecto (propuesta de la auditoría), salvo que
            // se pidan con el filtro de etapa.
            'expired' => $request->boolean('vencidas') || in_array('vencida', $stages, true),
            'window' => in_array($window, self::WINDOWS, true) ? $window : 'all',
            'view' => in_array($view, self::VIEWS, true) ? $view : 'tabla',
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'deadline',
            'direction' => $request->query('dir') === 'desc' ? 'desc' : 'asc',
            'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : now()->format('Y-m'),
        ];
    }

    /** Cuántos filtros de la hoja están activos (para el contador del botón «Filtros»). */
    public static function activeCount(array $filters): int
    {
        return count(array_filter([
            $filters['stages'] !== [], $filters['status'] !== '', $filters['modality'] !== '', $filters['institution_id'],
            $filters['owner_id'], $filters['mine'], $filters['from'], $filters['to'], $filters['expired'] && $filters['stages'] === [],
        ]));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Tender>
     */
    public function filtered(array $filters, ?User $user = null, bool $withWindow = true): Builder
    {
        $now = now();
        $search = $filters['search'];
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $query = Tender::query()
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $x) => $x
                ->where('reference', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhereHas('institution', fn (Builder $i) => $i->where('name', 'like', $like)->orWhere('acronym', 'like', $like))))
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['modality'] !== '', fn (Builder $q) => $q->where('purchase_type', $filters['modality']))
            ->when($filters['institution_id'], fn (Builder $q) => $q->where('institution_id', $filters['institution_id']))
            ->when($filters['owner_id'], fn (Builder $q) => $q->where('owner_id', $filters['owner_id']))
            ->when($filters['mine'] && $user, fn (Builder $q) => $q->where('owner_id', $user->id))
            ->when($filters['from'], fn (Builder $q) => $q->where('presentation_deadline', '>=', $filters['from'].' 00:00:00'))
            ->when($filters['to'], fn (Builder $q) => $q->where('presentation_deadline', '<=', $filters['to'].' 23:59:59'));

        if ($filters['stages'] !== []) {
            TenderStage::whereStage($query, $filters['stages'], $now);
        } elseif (! $filters['expired'] && $filters['status'] === '') {
            TenderStage::whereStage($query, array_values(array_diff(array_keys(TenderStage::STAGES), ['vencida'])), $now);
        }

        if ($withWindow) {
            match ($filters['window']) {
                'open' => BusinessMetrics::openTendersQuery($query),
                'week' => $this->closingThisWeek($query),
                'clarifications' => $this->clarificationsSoon($query),
                'presented' => TenderStage::whereStage($query, ['presentada'], $now),
                default => null,
            };
        }

        return $query;
    }

    /** Recepción de ofertas en los próximos 7 días (procesos vigentes). */
    public function closingThisWeek(Builder $query): Builder
    {
        return BusinessMetrics::openTendersQuery($query)->whereBetween('presentation_deadline', [now(), now()->addDays(7)]);
    }

    /** Cierre de aclaraciones en las próximas 72 horas (procesos vigentes). */
    public function clarificationsSoon(Builder $query): Builder
    {
        return BusinessMetrics::openTendersQuery($query)->whereBetween('clarification_deadline', [now(), now()->addHours(72)]);
    }

    /**
     * @param  Builder<Tender>  $query
     * @return Builder<Tender>
     */
    public function sorted(Builder $query, array $filters): Builder
    {
        $direction = $filters['direction'];
        $now = now();

        match ($filters['sort']) {
            'reference' => $query->orderBy('reference', $direction),
            'amount' => $query->orderBy('estimated_amount', $direction),
            'created' => $query->orderBy('created_at', $direction),
            'institution' => $query->orderBy(Institution::query()->select('name')->whereColumn('institutions.id', 'tenders.institution_id')->limit(1), $direction),
            'owner' => $query->orderBy(User::query()->select('name')->whereColumn('users.id', 'tenders.owner_id')->limit(1), $direction),
            'stage' => $this->orderByStage($query, $direction, $now),
            default => $this->orderByDeadline($query, $direction, $now),
        };

        return $query->orderByDesc('tenders.id');
    }

    /** Lo que vence antes, primero; sin plazo por delante, al final (lo más reciente arriba). */
    private function orderByDeadline(Builder $query, string $direction, $now): void
    {
        [$next, $bindings] = TenderStage::nextDeadlineSql($now);

        $query->orderByRaw("CASE WHEN {$next} IS NULL THEN 1 ELSE 0 END", $bindings)
            ->orderByRaw("{$next} {$direction}", $bindings)
            ->orderByDesc('presentation_deadline');
    }

    private function orderByStage(Builder $query, string $direction, $now): void
    {
        [$stage, $bindings] = TenderStage::sql($now);
        $cases = collect(array_keys(TenderStage::STAGES))->map(fn ($key, $i) => "WHEN '{$key}' THEN {$i}")->implode(' ');

        $query->orderByRaw("CASE {$stage} {$cases} END {$direction}", $bindings);
    }

    /**
     * Columnas e includes de una fila (sin N+1: institución y responsable en dos consultas).
     *
     * @param  Builder<Tender>  $query
     * @return Builder<Tender>
     */
    public function forRows(Builder $query): Builder
    {
        return $query->select([
            'tenders.id', 'tenders.institution_id', 'tenders.owner_id', 'tenders.reference', 'tenders.title',
            'tenders.purchase_type', 'tenders.status', 'tenders.estimated_amount', 'tenders.clarification_deadline',
            'tenders.presentation_deadline', 'tenders.opening_at', 'tenders.offer_submitted', 'tenders.result',
            'tenders.honducompras_stage', 'tenders.created_at',
        ])
            ->with(['institution:id,name,acronym', 'owner:id,name'])
            ->withCount('quotes');
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Tender $tender, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stage = TenderStage::of($tender, $now);
        $live = in_array($stage['key'], ['por_analizar', 'en_preparacion', 'presentada'], true);

        return [
            'id' => $tender->id,
            'reference' => $tender->reference,
            'title' => TenderText::display($tender->title),
            'institution' => $tender->institution?->only(['id', 'name', 'acronym']),
            'owner' => $tender->owner ? ['id' => $tender->owner->id, 'name' => $tender->owner->name] : null,
            'purchase_type' => $tender->purchase_type,
            'status' => $tender->status,
            'stage' => $stage,
            'estimated_amount' => $tender->estimated_amount,
            'next_deadline' => $live ? TenderStage::nextDeadline($tender, $now) : null,
            'clarification_deadline' => $tender->clarification_deadline?->format('Y-m-d\TH:i:s'),
            'presentation_deadline' => $tender->presentation_deadline?->format('Y-m-d\TH:i:s'),
            'opening_at' => $tender->opening_at?->format('Y-m-d\TH:i:s'),
            'result' => $tender->result,
            'quotes_count' => (int) ($tender->quotes_count ?? 0),
        ];
    }

    /**
     * Cifras de cabecera. «Vigentes» es la definición única de BusinessMetrics (cuadra con el
     * Dashboard y Reportes); el resto, una sola consulta sobre el mismo criterio de vigente.
     *
     * @return array{open: int, week: int, clarifications: int, presented: int, awaiting: int, expired: int, rate: float|null, won: int, decided: int}
     */
    public function stats(): array
    {
        $now = now();
        [$stage, $bindings] = TenderStage::sql($now);
        $n = $now->format('Y-m-d H:i:s');

        // Sobre el criterio único de «vigente» de BusinessMetrics.
        $open = BusinessMetrics::openTendersQuery(Tender::query())->toBase()->selectRaw(
            'SUM(CASE WHEN presentation_deadline BETWEEN ? AND ? THEN 1 ELSE 0 END) AS week_count,
             SUM(CASE WHEN clarification_deadline BETWEEN ? AND ? THEN 1 ELSE 0 END) AS clarifications_count',
            [$n, $now->copy()->addDays(7)->format('Y-m-d H:i:s'), $n, $now->copy()->addHours(72)->format('Y-m-d H:i:s')],
        )->first();

        $presented = Tender::query()->toBase()->selectRaw(
            "SUM(CASE WHEN {$stage} = 'presentada' THEN 1 ELSE 0 END) AS presented_count,
             SUM(CASE WHEN {$stage} = 'presentada' AND COALESCE(tenders.opening_at, tenders.presentation_deadline) < ? THEN 1 ELSE 0 END) AS awaiting_count,
             SUM(CASE WHEN {$stage} = 'vencida' THEN 1 ELSE 0 END) AS expired_count",
            [...$bindings, ...$bindings, $n, ...$bindings],
        )->first();
        $row = (object) ((array) $open + (array) $presented);

        $award = $this->metrics->awardRate();

        return [
            'open' => $this->metrics->openTenders(),
            'week' => (int) ($row->week_count ?? 0),
            'clarifications' => (int) ($row->clarifications_count ?? 0),
            'presented' => (int) ($row->presented_count ?? 0),
            'awaiting' => (int) ($row->awaiting_count ?? 0),
            'expired' => (int) ($row->expired_count ?? 0),
            'rate' => $award['rate'],
            'won' => $award['won'],
            'decided' => $award['decided'],
        ];
    }

    /**
     * Kanban por etapa: conteo real por columna (una consulta agrupada) y hasta BOARD_LIMIT
     * tarjetas por columna, las de plazo más cercano primero.
     *
     * @return array{columns: array<int, array<string, mixed>>, cards: array<int, array<string, mixed>>}
     */
    public function board(array $filters, ?User $user): array
    {
        $now = CarbonImmutable::now();
        [$stage, $bindings] = TenderStage::sql($now);

        $counts = $this->filtered($filters, $user)->toBase()
            ->selectRaw("{$stage} AS stage_key, COUNT(*) AS total", $bindings)
            ->groupBy('stage_key')
            ->pluck('total', 'stage_key');

        $keys = array_keys(TenderStage::STAGES);
        if (! $filters['expired']) {
            $keys = array_values(array_diff($keys, ['vencida']));
        }
        if ($filters['stages'] !== []) {
            $keys = array_values(array_intersect($keys, $filters['stages']));
        }

        $rows = $this->sorted($this->forRows($this->filtered($filters, $user)), ['sort' => 'deadline', 'direction' => 'asc'])
            ->limit(self::BOARD_LIMIT * count($keys))->get();

        $cards = [];
        $perColumn = [];
        foreach ($rows as $tender) {
            $row = $this->row($tender, $now);
            $key = $row['stage']['key'];
            if (! in_array($key, $keys, true) || ($perColumn[$key] = ($perColumn[$key] ?? 0) + 1) > self::BOARD_LIMIT) {
                continue;
            }
            $cards[] = $row;
        }

        return [
            'columns' => array_map(fn ($key) => [
                'id' => $key,
                'title' => TenderStage::STAGES[$key]['label'],
                'tone' => TenderStage::STAGES[$key]['tone'],
                'total' => (int) ($counts[$key] ?? 0),
            ], $keys),
            'cards' => $cards,
        ];
    }

    /**
     * Calendario del mes: cada plazo (aclaraciones, recepción, apertura) de las licitaciones
     * filtradas que cae en el mes, como un evento.
     *
     * @return array{month: string, events: array<int, array<string, mixed>>}
     */
    public function calendar(array $filters, ?User $user): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $filters['month'].'-01')->startOfDay();
        $end = $start->endOfMonth();
        $range = [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
        $now = CarbonImmutable::now();

        // En el calendario las vencidas sí cuentan: un plazo pasado sigue siendo un hecho del mes.
        $tenders = $this->forRows($this->filtered(['expired' => true] + $filters, $user))
            ->where(fn (Builder $q) => $q->whereBetween('clarification_deadline', $range)
                ->orWhereBetween('presentation_deadline', $range)
                ->orWhereBetween('opening_at', $range))
            ->limit(400)->get();

        $events = [];
        foreach ($tenders as $tender) {
            $row = $this->row($tender, $now);
            foreach (TenderStage::DEADLINE_LABELS as $key => $label) {
                $at = $tender->{$key};
                if ($at === null || $at->lt($start) || $at->gt($end)) {
                    continue;
                }
                $events[] = [
                    'id' => "{$tender->id}-{$key}",
                    'tender_id' => $tender->id,
                    'kind' => $key,
                    'label' => $label,
                    'at' => $at->format('Y-m-d\TH:i:s'),
                    'reference' => $row['reference'],
                    'title' => $row['title'],
                    'institution' => $row['institution'],
                    'stage' => $row['stage'],
                    'past' => $at->lt($now),
                ];
            }
        }

        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return ['month' => $filters['month'], 'events' => $events];
    }

    /**
     * Filas para los exportes (PDF y Excel) con los mismos filtros y orden de la pantalla.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function exportRows(array $filters, ?User $user, int $limit): Collection
    {
        $now = CarbonImmutable::now();

        return $this->sorted($this->forRows($this->filtered($filters, $user)), $filters)
            ->limit($limit)->get()
            ->map(fn (Tender $tender) => $this->row($tender, $now));
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
    }
}
