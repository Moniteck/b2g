<?php

namespace App\Services;

use App\Models\CollectionInvoice;
use App\Models\Document;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Builder;

/**
 * Indicadores del dashboard.
 *
 * El estado documental y el resultado comercial se cuentan por separado: una
 * cotizacion presentada y no adjudicada no cuenta como ganada ni como perdida.
 *
 * Cada tabla se lee con UNA consulta de agregados condicionales (SUM(CASE …)): el tablero
 * pasa de 18 consultas de conteo a 6, con los mismos filtros (y los scopes de empresa).
 */
class DashboardMetricsService
{
    private const OPEN_TENDER_STATUSES = ['Activa', 'En preparación'];

    private const PRESENTED_QUOTE_STATUSES = ['Presentada', 'Sustituida'];

    public function metrics(): array
    {
        $today = today();

        $quotes = $this->aggregate(Quote::query(), [
            'presented' => ['status IN (?, ?)', self::PRESENTED_QUOTE_STATUSES],
            'accepted' => ['outcome = ?', ['Ganada']],
            'lost' => ['outcome = ?', ['Perdida']],
            'overdue' => ['status IN (?, ?) AND outcome = ? AND DATE(quoted_at) <= ?', [...self::PRESENTED_QUOTE_STATUSES, 'Pendiente', $today->copy()->subDays(30)->toDateString()]],
        ]);

        $tenders = $this->aggregate(Tender::query(), [
            'submitted' => ['offer_submitted = ?', [true]],
            'won' => ['result = ?', ['Ganada']],
            'closingToday' => ['status IN (?, ?) AND DATE(closing_date) = ?', [...self::OPEN_TENDER_STATUSES, $today->toDateString()]],
            'closingSoon' => ['status IN (?, ?) AND closing_date BETWEEN ? AND ?', [...self::OPEN_TENDER_STATUSES, $today->copy()->addDay(), $today->copy()->addDays(30)]],
            'clarificationSoon' => ['status IN (?, ?) AND clarification_deadline BETWEEN ? AND ?', [...self::OPEN_TENDER_STATUSES, now(), now()->addDays(7)]],
            'active' => ['status IN (?, ?)', self::OPEN_TENDER_STATUSES],
        ]);

        $opportunities = Opportunity::query()->whereNotIn('stage', Opportunity::CLOSED_STAGES)
            ->selectRaw('COALESCE(SUM(amount * probability / 100), 0) AS pipeline, COUNT(*) AS total')
            ->toBase()->first();

        $documents = $this->aggregate(Document::query(), [
            'expiring' => ['expires_at BETWEEN ? AND ?', [$today, $today->copy()->addDays(30)]],
            'expired' => ['DATE(expires_at) < ?', [$today->toDateString()]],
        ]);

        $institutions = $this->aggregate(Institution::query(), [
            'high' => ['LOWER(potential) = ?', ['alto']],
            'medium' => ['LOWER(potential) = ?', ['medio']],
            'low' => ['LOWER(potential) = ?', ['bajo']],
        ]);

        return [
            'pipeline' => (float) ($opportunities->pipeline ?? 0),
            'opportunities' => (int) ($opportunities->total ?? 0),
            'quoteRate' => $quotes['presented'] ? round($quotes['accepted'] / $quotes['presented'] * 100, 1) : 0,
            'acceptedQuotes' => $quotes['accepted'],
            'lostQuotes' => $quotes['lost'],
            'presentedQuotes' => $quotes['presented'],
            'awardRate' => $tenders['submitted'] ? round($tenders['won'] / $tenders['submitted'] * 100) : 0,
            'wonTenders' => $tenders['won'],
            'submittedTenders' => $tenders['submitted'],
            'overdueReceivables' => (float) CollectionInvoice::query()->where('status', '!=', 'Pagada')->whereDate('due_at', '<', $today)->sum('amount'),
            'expiringDocuments' => $documents['expiring'],
            'expiredDocuments' => $documents['expired'],
            'overdueQuotes' => $quotes['overdue'],
            'institutions' => $institutions,
            'tenders' => [
                'closingToday' => $tenders['closingToday'],
                'closingSoon' => $tenders['closingSoon'],
                'clarificationSoon' => $tenders['clarificationSoon'],
                'active' => $tenders['active'],
            ],
        ];
    }

    /**
     * Varios conteos sobre la misma tabla en una sola consulta.
     *
     * @param  array<string, array{0: string, 1: array<int, mixed>}>  $counts  alias => [condición SQL, enlaces]
     * @return array<string, int>
     */
    private function aggregate(Builder $query, array $counts): array
    {
        $columns = [];
        $bindings = [];

        foreach ($counts as $alias => [$condition, $values]) {
            $columns[] = "COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0) AS {$alias}";
            array_push($bindings, ...$values);
        }

        $row = (array) $query->selectRaw(implode(', ', $columns), $bindings)->toBase()->first();

        return array_map(fn (string $alias) => (int) ($row[$alias] ?? 0), array_combine(array_keys($counts), array_keys($counts)));
    }
}
