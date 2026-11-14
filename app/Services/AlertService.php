<?php

namespace App\Services;

use App\Models\CollectionInvoice;
use App\Models\Document;
use App\Models\Tender;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Cache;

/**
 * Alertas activas de la empresa (documentos vencidos o por vencer, cobros en mora, cierres de
 * licitación cercanos). Se calculan una vez por empresa y se guardan un minuto: el dashboard y el
 * contador de la cabecera leen la misma caché, sin consultas en cada petición.
 */
class AlertService
{
    public const TTL_SECONDS = 60;

    public function __construct(protected CompanyContext $context) {}

    public static function cacheKey(?int $companyId): string
    {
        return 'alerts.company.'.($companyId ?? 0);
    }

    public static function forget(?int $companyId): void
    {
        Cache::forget(self::cacheKey($companyId));
    }

    /**
     * @return array<int, array{type: string, severity: string, message: string}>
     */
    public function active(?int $companyId = null): array
    {
        $companyId ??= $this->context->currentCompanyId();

        if ($companyId === null) {
            return [];
        }

        return Cache::remember(self::cacheKey($companyId), self::TTL_SECONDS, fn () => $this->build($companyId));
    }

    public function count(?int $companyId = null): int
    {
        return count($this->active($companyId));
    }

    /**
     * @return array<int, array{type: string, severity: string, message: string}>
     */
    protected function build(int $companyId): array
    {
        $alerts = [];
        $today = today();

        $documents = Document::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $today->copy()->addDays(30))
            ->orderBy('expires_at')
            ->limit(50)
            ->get(['id', 'name', 'expires_at']);

        foreach ($documents as $document) {
            $alerts[] = $document->expires_at->lt($today)
                ? ['type' => 'documentos', 'severity' => 'danger', 'message' => "{$document->name} está vencido desde el {$document->expires_at->format('d/m/Y')}"]
                : ['type' => 'documentos', 'severity' => 'warning', 'message' => "{$document->name} vence el {$document->expires_at->format('d/m/Y')}"];
        }

        $invoices = CollectionInvoice::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', '!=', 'Pagada')
            ->where('due_at', '<', $today)
            ->orderBy('due_at')
            ->limit(50)
            ->get(['id', 'number', 'due_at']);

        foreach ($invoices as $invoice) {
            $alerts[] = ['type' => 'cobros', 'severity' => 'danger', 'message' => "Factura {$invoice->number} está en mora desde el {$invoice->due_at->format('d/m/Y')}"];
        }

        $tenders = Tender::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['Activa', 'En preparación'])
            ->where(fn ($query) => $query
                ->whereBetween('closing_date', [$today, $today->copy()->addDays(7)])
                ->orWhereBetween('clarification_deadline', [now(), now()->addDays(7)]))
            ->orderBy('closing_date')
            ->limit(50)
            ->get(['id', 'reference', 'closing_date', 'clarification_deadline']);

        foreach ($tenders as $tender) {
            if ($tender->closing_date !== null && $tender->closing_date->between($today, $today->copy()->addDays(7))) {
                $alerts[] = ['type' => 'licitaciones', 'severity' => 'warning', 'message' => "{$tender->reference} cierra el {$tender->closing_date->format('d/m/Y')}"];
            }
            if ($tender->clarification_deadline !== null && $tender->clarification_deadline->between(now(), now()->addDays(7))) {
                $alerts[] = ['type' => 'licitaciones', 'severity' => 'warning', 'message' => "Aclaraciones de {$tender->reference} cierran el {$tender->clarification_deadline->format('d/m/Y H:i')}"];
            }
        }

        return collect($alerts)->sortBy(fn (array $alert) => $alert['severity'] === 'danger' ? 0 : 1)->values()->all();
    }
}
