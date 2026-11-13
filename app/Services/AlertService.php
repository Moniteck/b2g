<?php

namespace App\Services;

use App\Models\CollectionInvoice;
use App\Models\Document;
use App\Models\Tender;

class AlertService
{
    public function active(): array
    {
        $alerts = [];

        foreach (Document::query()->whereDate('expires_at', '<', today())->orderBy('expires_at')->get(['id', 'name', 'expires_at']) as $document) {
            $alerts[] = ['type' => 'documentos', 'severity' => 'danger', 'message' => "{$document->name} está vencido desde el {$document->expires_at->format('d/m/Y')}"];
        }

        foreach (Document::query()->whereBetween('expires_at', [today(), today()->addDays(30)])->orderBy('expires_at')->get(['id', 'name', 'expires_at']) as $document) {
            $alerts[] = ['type' => 'documentos', 'severity' => 'warning', 'message' => "{$document->name} vence el {$document->expires_at->format('d/m/Y')}"];
        }

        foreach (CollectionInvoice::query()->where('status', '!=', 'Pagada')->whereDate('due_at', '<', today())->orderBy('due_at')->get(['id', 'number', 'due_at']) as $invoice) {
            $alerts[] = ['type' => 'cobros', 'severity' => 'danger', 'message' => "Factura {$invoice->number} está en mora desde el {$invoice->due_at->format('d/m/Y')}"];
        }

        foreach (Tender::query()->whereIn('status', ['Activa', 'En preparación'])->whereBetween('closing_date', [today(), today()->addDays(7)])->orderBy('closing_date')->get(['id', 'reference', 'closing_date']) as $tender) {
            $alerts[] = ['type' => 'licitaciones', 'severity' => 'warning', 'message' => "{$tender->reference} cierra el {$tender->closing_date->format('d/m/Y')}"];
        }

        foreach (Tender::query()->whereIn('status', ['Activa', 'En preparación'])->whereNotNull('clarification_deadline')->whereBetween('clarification_deadline', [now(), now()->addDays(7)])->orderBy('clarification_deadline')->get(['id', 'reference', 'clarification_deadline']) as $tender) {
            $alerts[] = ['type' => 'licitaciones', 'severity' => 'warning', 'message' => "Aclaraciones de {$tender->reference} cierran el {$tender->clarification_deadline->format('d/m/Y H:i')}"];
        }

        return collect($alerts)->sortBy(fn (array $alert) => $alert['severity'] === 'danger' ? 0 : 1)->values()->all();
    }
}
