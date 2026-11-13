<?php

namespace App\Services;

use App\Models\CollectionInvoice;
use App\Models\Document;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;

/**
 * Indicadores del dashboard.
 *
 * El estado documental y el resultado comercial se cuentan por separado: una
 * cotizacion presentada y no adjudicada no cuenta como ganada ni como perdida.
 */
class DashboardMetricsService
{
    public function metrics(): array
    {
        $presented = (clone Quote::query())->whereIn('status', ['Presentada', 'Sustituida'])->count();
        $accepted = (clone Quote::query())->where('outcome', 'Ganada')->count();
        $lost = (clone Quote::query())->where('outcome', 'Perdida')->count();
        $submittedTenders = Tender::query()->where('offer_submitted', true)->count();
        $wonTenders = Tender::query()->where('result', 'Ganada')->count();
        $today = today();

        return [
            'pipeline' => (float) Opportunity::query()->whereNotIn('stage', ['cerrado', 'no_adjudicado'])->selectRaw('COALESCE(SUM(amount * probability / 100), 0) total')->value('total'),
            'opportunities' => Opportunity::query()->whereNotIn('stage', ['cerrado', 'no_adjudicado'])->count(),
            'quoteRate' => $presented ? round($accepted / $presented * 100, 1) : 0,
            'acceptedQuotes' => $accepted,
            'lostQuotes' => $lost,
            'presentedQuotes' => $presented,
            'awardRate' => $submittedTenders ? round($wonTenders / $submittedTenders * 100) : 0,
            'wonTenders' => $wonTenders,
            'submittedTenders' => $submittedTenders,
            'overdueReceivables' => (float) CollectionInvoice::query()->where('status', '!=', 'Pagada')->whereDate('due_at', '<', $today)->sum('amount'),
            'expiringDocuments' => Document::query()->whereBetween('expires_at', [$today, $today->copy()->addDays(30)])->count(),
            'expiredDocuments' => Document::query()->whereDate('expires_at', '<', $today)->count(),
            'overdueQuotes' => Quote::query()->whereIn('status', ['Presentada', 'Sustituida'])->where('outcome', 'Pendiente')->whereDate('quoted_at', '<=', $today->copy()->subDays(30))->count(),
            'institutions' => [
                'high' => Institution::query()->whereRaw('LOWER(potential) = ?', ['alto'])->count(),
                'medium' => Institution::query()->whereRaw('LOWER(potential) = ?', ['medio'])->count(),
                'low' => Institution::query()->whereRaw('LOWER(potential) = ?', ['bajo'])->count(),
            ],
            'tenders' => [
                'closingToday' => Tender::query()->whereIn('status', ['Activa', 'En preparación'])->whereDate('closing_date', $today)->count(),
                'closingSoon' => Tender::query()->whereIn('status', ['Activa', 'En preparación'])->whereBetween('closing_date', [$today->copy()->addDay(), $today->copy()->addDays(30)])->count(),
                'clarificationSoon' => Tender::query()->whereIn('status', ['Activa', 'En preparación'])->whereBetween('clarification_deadline', [now(), now()->addDays(7)])->count(),
                'active' => Tender::query()->whereIn('status', ['Activa', 'En preparación'])->count(),
            ],
        ];
    }
}
