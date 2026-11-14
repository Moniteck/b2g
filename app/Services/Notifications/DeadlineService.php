<?php

namespace App\Services\Notifications;

use App\Models\CollectionInvoice;
use App\Models\Event;
use App\Models\Quote;
use Illuminate\Support\Facades\Cache;

/**
 * «Plazos de esta semana» del dashboard: plazos pendientes de los próximos 7 días de la empresa
 * activa (calendario + facturas por cobrar + vigencia de cotizaciones), con caché corta.
 */
class DeadlineService
{
    public const TTL_SECONDS = 60;

    public const DAYS = 7;

    public static function cacheKey(int $companyId): string
    {
        return "dashboard.deadlines.{$companyId}";
    }

    /**
     * @return array<int, array{id: string, title: string, detail: string|null, due_at: string, href: string, kind: string}>
     */
    public function forCompany(?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }

        return Cache::remember(self::cacheKey($companyId), self::TTL_SECONDS, fn () => $this->build($companyId));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function build(int $companyId): array
    {
        $from = now()->startOfDay();
        $until = now()->addDays(self::DAYS)->endOfDay();

        $events = Event::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pending()
            ->whereBetween('starts_at', [$from, $until])
            ->orderBy('starts_at')
            ->limit(30)
            ->get(['id', 'title', 'label', 'type', 'starts_at', 'all_day', 'reference_type', 'reference_id', 'contract_id', 'origin'])
            ->map(fn (Event $event) => [
                'id' => "event-{$event->id}",
                'title' => $event->title,
                'detail' => $event->typeLabel().($event->all_day ? '' : ' · '.NotificationFeed::time($event->starts_at)),
                'due_at' => $event->starts_at->toDateTimeString(),
                'href' => $event->sourceHref(),
                'kind' => Event::KINDS[$event->reference_type ?? 'manual'] ?? 'event',
            ]);

        $invoices = CollectionInvoice::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', '!=', 'Pagada')
            ->whereBetween('due_at', [$from, $until])
            ->orderBy('due_at')
            ->limit(10)
            ->get(['id', 'number', 'concept', 'due_at'])
            ->map(fn (CollectionInvoice $invoice) => [
                'id' => "collection-{$invoice->id}",
                'title' => "Vence la factura {$invoice->number}",
                'detail' => $invoice->concept,
                'due_at' => $invoice->due_at->toDateTimeString(),
                'href' => '/cobros',
                'kind' => 'collection',
            ]);

        $quotes = Quote::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereIn('status', ['Emitida', 'Enviada', 'Presentada'])
            ->where(fn ($query) => $query->whereNull('outcome')->orWhere('outcome', 'Pendiente'))
            ->whereNotNull('quoted_at')
            ->whereNotNull('validity_days')
            ->where('quoted_at', '>=', now()->subYear())
            ->limit(200)
            ->get(['id', 'number', 'quoted_at', 'validity_days'])
            ->map(function (Quote $quote) {
                $expires = $quote->quoted_at->copy()->startOfDay()->addDays((int) $quote->validity_days);

                return ['quote' => $quote, 'expires' => $expires];
            })
            ->filter(fn (array $row) => $row['expires']->between($from, $until))
            ->map(fn (array $row) => [
                'id' => "quote-{$row['quote']->id}",
                'title' => "Vence la vigencia de la cotización {$row['quote']->number}",
                'detail' => 'Vigencia de la oferta',
                'due_at' => $row['expires']->toDateTimeString(),
                'href' => '/cotizaciones?cotizacion='.$row['quote']->id,
                'kind' => 'quote',
            ]);

        return $events->concat($invoices)->concat($quotes)
            ->sortBy('due_at')
            ->values()
            ->take(30)
            ->all();
    }
}
