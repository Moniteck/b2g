<?php

namespace App\Services;

use App\Models\CollectionInvoice;
use App\Models\Document;
use App\Models\Tender;
use App\Services\Metrics\BusinessMetrics;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Cache;

/**
 * Alertas activas de la empresa (documentos vencidos o por vencer, cobros en mora, plazos de
 * licitación cercanos). Se calculan una vez por empresa y se guardan un minuto: el contador de la
 * cabecera lee esta caché sin consultas en cada petición. La clave lleva la versión de
 * `BusinessMetrics`, así que guardar un documento, una factura o una licitación la renueva al
 * instante. Cada alerta trae `href` al registro (B-29), no a la lista.
 */
class AlertService
{
    public const TTL_SECONDS = 60;

    public function __construct(protected CompanyContext $context) {}

    public static function cacheKey(?int $companyId): string
    {
        return 'alerts.company.'.($companyId ?? 0).'.'.($companyId === null ? '0' : BusinessMetrics::version($companyId));
    }

    public static function forget(?int $companyId): void
    {
        Cache::forget(self::cacheKey($companyId));
    }

    /**
     * @return array<int, array{type: string, severity: string, message: string, href: string}>
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
     * @return array<int, array{type: string, severity: string, message: string, href: string}>
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
            $href = "/directorio?documento={$document->id}";
            $alerts[] = $document->expires_at->lt($today)
                ? ['type' => 'documentos', 'severity' => 'danger', 'message' => "{$document->name} está vencido desde el {$document->expires_at->format('d/m/Y')}", 'href' => $href]
                : ['type' => 'documentos', 'severity' => 'warning', 'message' => "{$document->name} vence el {$document->expires_at->format('d/m/Y')}", 'href' => $href];
        }

        $invoices = CollectionInvoice::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', '!=', CollectionInvoice::STATUS_PAID)
            ->where('due_at', '<', $today)
            ->orderBy('due_at')
            ->limit(50)
            ->get(['id', 'number', 'due_at']);

        foreach ($invoices as $invoice) {
            $alerts[] = ['type' => 'cobros', 'severity' => 'danger', 'message' => "Factura {$invoice->number} está en mora desde el {$invoice->due_at->format('d/m/Y')}", 'href' => '/cobros'];
        }

        // Cierre = recepción de ofertas (`presentation_deadline`), el mismo criterio de Licitaciones.
        $tenders = Tender::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', Tender::OPEN_STATUSES)
            ->where(fn ($query) => $query
                ->whereBetween('presentation_deadline', [now(), now()->addDays(7)])
                ->orWhereBetween('clarification_deadline', [now(), now()->addDays(7)]))
            ->orderBy('presentation_deadline')
            ->limit(50)
            ->get(['id', 'reference', 'presentation_deadline', 'clarification_deadline']);

        foreach ($tenders as $tender) {
            $href = "/licitaciones/{$tender->id}";
            if ($tender->presentation_deadline !== null && $tender->presentation_deadline->between(now(), now()->addDays(7))) {
                $alerts[] = ['type' => 'licitaciones', 'severity' => 'warning', 'message' => "{$tender->reference} recibe ofertas hasta el {$tender->presentation_deadline->format('d/m/Y H:i')}", 'href' => $href];
            }
            if ($tender->clarification_deadline !== null && $tender->clarification_deadline->between(now(), now()->addDays(7))) {
                $alerts[] = ['type' => 'licitaciones', 'severity' => 'warning', 'message' => "Aclaraciones de {$tender->reference} cierran el {$tender->clarification_deadline->format('d/m/Y H:i')}", 'href' => $href];
            }
        }

        return collect($alerts)->sortBy(fn (array $alert) => $alert['severity'] === 'danger' ? 0 : 1)->values()->all();
    }
}
