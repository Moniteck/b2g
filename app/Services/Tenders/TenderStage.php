<?php

namespace App\Services\Tenders;

use App\Models\Tender;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Etapa del flujo de una licitación, derivada del estado guardado, de la oferta y de las fechas.
 *
 * Es la ÚNICA definición de «etapa» que usan la lista (tabla, kanban, calendario), la ficha, los
 * exportes y el comando diario `licitaciones:actualizar-estados`:
 *
 *   por_analizar     Activa (todavía no se trabaja la oferta)
 *   en_preparacion   En preparación
 *   presentada       Presentada, o con la oferta marcada como entregada
 *                    («Esperando resultado» si la apertura —o la recepción, sin apertura— ya pasó)
 *   adjudicada       Adjudicada
 *   no_adjudicada    No adjudicada / Perdida
 *   desierta         Desierta / Cancelada
 *   vencida          La recepción de ofertas pasó sin oferta (guardada como «Vencida» por el
 *                    comando diario, o calculada al vuelo si el comando aún no corrió: B-19)
 *
 *   TenderStage::of($tender, now())   // ['key' => 'vencida', 'label' => 'Vencida sin oferta', …]
 *   TenderStage::whereStage($query, ['presentada'])
 */
final class TenderStage
{
    public const STATUS_EXPIRED = 'Vencida';

    /** Orden del flujo (columnas del kanban). */
    public const STAGES = [
        'por_analizar' => ['label' => 'Por analizar', 'tone' => 'info', 'icon' => 'magnify-scan'],
        'en_preparacion' => ['label' => 'En preparación', 'tone' => 'warning', 'icon' => 'progress-pencil'],
        'presentada' => ['label' => 'Presentada', 'tone' => 'info', 'icon' => 'send-check-outline'],
        'adjudicada' => ['label' => 'Adjudicada', 'tone' => 'ok', 'icon' => 'trophy-outline'],
        'no_adjudicada' => ['label' => 'No adjudicada', 'tone' => 'error', 'icon' => 'close-circle-outline'],
        'desierta' => ['label' => 'Desierta o cancelada', 'tone' => 'neutral', 'icon' => 'cancel'],
        'vencida' => ['label' => 'Vencida sin oferta', 'tone' => 'neutral', 'icon' => 'timer-off-outline'],
    ];

    /** Etapas en las que el proceso sigue vivo y sus plazos importan. */
    public const LIVE = ['por_analizar', 'en_preparacion', 'presentada'];

    /** Estado que se guarda al mover una tarjeta del kanban a cada etapa. */
    public const STATUS_FOR_STAGE = [
        'por_analizar' => 'Activa',
        'en_preparacion' => 'En preparación',
        'presentada' => 'Presentada',
        'adjudicada' => 'Adjudicada',
        'no_adjudicada' => 'No adjudicada',
        'desierta' => 'Desierta',
    ];

    /**
     * @return array{key: string, label: string, tone: string, icon: string, awaiting_result: bool, status_label: string}
     */
    public static function of(Tender $tender, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $key = self::keyOf($tender->status, (bool) $tender->offer_submitted, $tender->presentation_deadline, $now);
        $stage = self::STAGES[$key];

        $awaiting = $key === 'presentada' && ($moment = $tender->opening_at ?? $tender->presentation_deadline) !== null && $moment->lt($now);

        return [
            'key' => $key,
            'label' => $stage['label'],
            'tone' => $stage['tone'],
            'icon' => $stage['icon'],
            'awaiting_result' => $awaiting,
            // Lo que ve la persona en el badge: «Esperando resultado» dice más que «Presentada».
            'status_label' => $awaiting ? 'Esperando resultado' : ($key === 'desierta' ? ($tender->status ?: $stage['label']) : $stage['label']),
        ];
    }

    public static function keyOf(?string $status, bool $offerSubmitted, ?CarbonInterface $presentation, CarbonInterface $now): string
    {
        $open = in_array($status, Tender::OPEN_STATUSES, true);

        return match (true) {
            ($open || $status === self::STATUS_EXPIRED) && $offerSubmitted => 'presentada',
            $open && $presentation !== null && $presentation->lt($now) => 'vencida',
            $status === 'Activa' => 'por_analizar',
            $status === 'En preparación' => 'en_preparacion',
            $status === 'Presentada' => 'presentada',
            $status === 'Adjudicada' => 'adjudicada',
            in_array($status, ['No adjudicada', 'Perdida'], true) => 'no_adjudicada',
            in_array($status, ['Desierta', 'Cancelada'], true) => 'desierta',
            $status === self::STATUS_EXPIRED => 'vencida',
            default => 'por_analizar',
        };
    }

    /**
     * La misma regla en SQL (MySQL y SQLite), para filtrar, contar y ordenar sin traer filas.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function sql(?CarbonInterface $now = null, string $table = 'tenders'): array
    {
        $now ??= now();
        $open = "{$table}.status IN ('Activa','En preparación')";
        $expired = "'".self::STATUS_EXPIRED."'";

        $sql = "(CASE
            WHEN ({$open} OR {$table}.status = {$expired}) AND {$table}.offer_submitted = 1 THEN 'presentada'
            WHEN {$open} AND {$table}.presentation_deadline IS NOT NULL AND {$table}.presentation_deadline < ? THEN 'vencida'
            WHEN {$table}.status = 'Activa' THEN 'por_analizar'
            WHEN {$table}.status = 'En preparación' THEN 'en_preparacion'
            WHEN {$table}.status = 'Presentada' THEN 'presentada'
            WHEN {$table}.status = 'Adjudicada' THEN 'adjudicada'
            WHEN {$table}.status IN ('No adjudicada','Perdida') THEN 'no_adjudicada'
            WHEN {$table}.status IN ('Desierta','Cancelada') THEN 'desierta'
            WHEN {$table}.status = {$expired} THEN 'vencida'
            ELSE 'por_analizar' END)";

        return [$sql, [$now->format('Y-m-d H:i:s')]];
    }

    /**
     * @param  Builder<Tender>  $query
     * @param  array<int, string>  $stages
     * @return Builder<Tender>
     */
    public static function whereStage(Builder $query, array $stages, ?CarbonInterface $now = null): Builder
    {
        $stages = array_values(array_intersect($stages, array_keys(self::STAGES)));

        if ($stages === []) {
            return $query;
        }

        [$sql, $bindings] = self::sql($now);
        $marks = implode(',', array_fill(0, count($stages), '?'));

        return $query->whereRaw("{$sql} IN ({$marks})", [...$bindings, ...$stages]);
    }

    /**
     * Próximo plazo que viene (aclaraciones, recepción o apertura: el primero que no pasó).
     * En SQL, para ordenar la lista por él; null si no queda ninguno.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function nextDeadlineSql(?CarbonInterface $now = null, string $table = 'tenders'): array
    {
        $n = ($now ?? now())->format('Y-m-d H:i:s');
        [$c, $p, $o] = ["{$table}.clarification_deadline", "{$table}.presentation_deadline", "{$table}.opening_at"];

        $sql = "(CASE
            WHEN {$c} >= ? AND ({$p} IS NULL OR {$p} < ? OR {$c} <= {$p}) AND ({$o} IS NULL OR {$o} < ? OR {$c} <= {$o}) THEN {$c}
            WHEN {$p} >= ? AND ({$o} IS NULL OR {$o} < ? OR {$p} <= {$o}) THEN {$p}
            WHEN {$o} >= ? THEN {$o}
            ELSE NULL END)";

        return [$sql, [$n, $n, $n, $n, $n, $n]];
    }

    /**
     * Próximo plazo de una licitación viva, con su tipo.
     *
     * @return array{key: string, label: string, at: string}|null
     */
    public static function nextDeadline(Tender $tender, ?CarbonInterface $now = null): ?array
    {
        $now ??= now();
        $candidates = array_filter([
            'clarification_deadline' => $tender->clarification_deadline,
            'presentation_deadline' => $tender->presentation_deadline,
            'opening_at' => $tender->opening_at,
        ], fn ($at) => $at !== null && $at->gte($now));

        if ($candidates === []) {
            return null;
        }

        asort($candidates);
        $key = array_key_first($candidates);

        return ['key' => $key, 'label' => self::DEADLINE_LABELS[$key], 'at' => $candidates[$key]->format('Y-m-d\TH:i:s')];
    }

    public const DEADLINE_LABELS = [
        'clarification_deadline' => 'Aclaraciones',
        'presentation_deadline' => 'Recepción de ofertas',
        'opening_at' => 'Apertura',
    ];

    /**
     * @return array<int, array{key: string, label: string, tone: string, icon: string}>
     */
    public static function options(): array
    {
        return array_map(fn ($key, $stage) => ['key' => $key] + $stage, array_keys(self::STAGES), self::STAGES);
    }
}
