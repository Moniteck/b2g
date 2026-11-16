<?php

namespace App\Services\Tenders;

use App\Models\DocumentExtraction;
use App\Models\Opportunity;
use App\Models\Tender;

/**
 * Proceso de una licitación para la ficha: los pasos del stepper y el SIGUIENTE PASO del botón de
 * la cabecera, según la etapa y lo que ya existe (pliego, análisis, cotización, participantes).
 *
 *   Importar pliego → Revisar análisis → Crear cotización → Registrar participantes → Registrar resultado
 *
 *   TenderFlow::for($tender, $stage, $analysis, $quotesCount, $opportunity, $participants, $can)
 */
final class TenderFlow
{
    /**
     * @param  array<string, mixed>  $stage  TenderStage::of()
     * @param  array<string, bool>  $can
     * @return array{steps: array<int, array<string, mixed>>, next: array<string, mixed>|null}
     */
    public static function for(Tender $tender, array $stage, ?DocumentExtraction $analysis, int $quotes, ?Opportunity $opportunity, int $participants, bool $hasCapture, array $can, bool $aiReady): array
    {
        $key = $stage['key'];
        $presented = in_array($key, ['presentada', 'adjudicada', 'no_adjudicada'], true);
        $decided = in_array($key, ['adjudicada', 'no_adjudicada'], true);
        $closed = in_array($key, ['vencida', 'desierta'], true);
        $analysisStatus = $analysis?->status;

        $steps = [
            ['key' => 'registro', 'label' => $hasCapture ? 'Captura importada' : 'Registrada', 'state' => 'done'],
            ['key' => 'pliego', 'label' => 'Pliego analizado', 'state' => match (true) {
                $analysisStatus === DocumentExtraction::CONFIRMED => 'done',
                in_array($analysisStatus, [DocumentExtraction::REVIEW, DocumentExtraction::QUEUED, DocumentExtraction::PROCESSING], true) => 'current',
                $analysisStatus === DocumentExtraction::FAILED => 'error',
                $presented || $quotes > 0 => 'skipped',
                default => 'todo',
            }],
            ['key' => 'cotizacion', 'label' => 'Cotización', 'state' => $quotes > 0 ? 'done' : ($presented ? 'skipped' : 'todo')],
            ['key' => 'presentada', 'label' => 'Oferta presentada', 'state' => $presented ? 'done' : ($closed ? 'error' : 'todo')],
            ['key' => 'resultado', 'label' => $key === 'no_adjudicada' ? 'No adjudicada' : ($key === 'adjudicada' ? 'Adjudicada' : 'Resultado'), 'state' => match (true) {
                $key === 'adjudicada' => 'done',
                $key === 'no_adjudicada' => 'error',
                $stage['awaiting_result'] => 'current',
                default => 'todo',
            }],
        ];

        // El primer paso pendiente queda como «actual» si ninguno lo es.
        if (! $closed && ! $decided && ! collect($steps)->contains('state', 'current')) {
            foreach ($steps as $i => $step) {
                if ($step['state'] === 'todo') {
                    $steps[$i]['state'] = 'current';
                    break;
                }
            }
        }

        if ($closed) {
            // Cerrada sin oferta: lo que quedó pendiente ya no se hará.
            $steps = array_map(fn (array $step) => in_array($step['state'], ['current', 'todo'], true) ? ['state' => 'skipped'] + $step : $step, $steps);
            $steps[] = ['key' => 'cierre', 'label' => $key === 'vencida' ? 'Vencida sin oferta' : ($tender->status ?: 'Cerrada'), 'state' => 'error'];
        }

        return ['steps' => $steps, 'next' => $closed || $decided || ! ($can['update'] ?? false) ? null : self::next($tender, $key, $analysis, $quotes, $opportunity, $participants, $stage['awaiting_result'], $can, $aiReady)];
    }

    /**
     * @return array<string, mixed>|null { label, icon, action: upload|href|post, href?, hint }
     */
    private static function next(Tender $tender, string $key, ?DocumentExtraction $analysis, int $quotes, ?Opportunity $opportunity, int $participants, bool $awaiting, array $can, bool $aiReady): ?array
    {
        $status = $analysis?->status;

        if ($key !== 'presentada') {
            if ($status === null && ($can['useAi'] ?? false) && $aiReady) {
                return ['label' => 'Importar pliego', 'icon' => 'file-upload-outline', 'action' => 'upload', 'hint' => 'Sube el PDF del pliego para extraer fechas, garantías, documentos y productos.'];
            }
            if ($analysis !== null && $analysis->isPending()) {
                return ['label' => 'Analizando el pliego…', 'icon' => 'progress-clock', 'action' => 'tab', 'tab' => 'analisis', 'busy' => true, 'hint' => 'El análisis corre en segundo plano; la ficha se actualiza sola.'];
            }
            if ($status === DocumentExtraction::REVIEW) {
                return ['label' => 'Revisar análisis', 'icon' => 'clipboard-check-outline', 'action' => 'href', 'href' => route('tenders.bid.review', [$tender, $analysis]), 'hint' => 'Confirma los datos del pliego con su cita antes de aplicarlos.'];
            }
            if ($quotes === 0 && ($can['createQuote'] ?? false)) {
                $fromBid = $status === DocumentExtraction::CONFIRMED && ! empty($analysis->extracted['lists']['items'] ?? []) && empty($analysis->evidence['quote_id'] ?? null);

                return $fromBid
                    ? ['label' => 'Crear cotización', 'icon' => 'file-document-plus-outline', 'action' => 'post', 'href' => route('tenders.bid.quote', [$tender, $analysis]), 'hint' => 'Borrador con los productos del pliego, con precio en cero para completar.']
                    : ['label' => 'Crear cotización', 'icon' => 'file-document-plus-outline', 'action' => 'href', 'href' => '/cotizaciones?nueva=1&tender_id='.$tender->id, 'hint' => 'Arma la oferta en Cotizaciones y vincúlala a esta licitación.'];
            }
            if ($quotes > 0) {
                return ['label' => 'Ver cotizaciones', 'icon' => 'file-document-outline', 'action' => 'href', 'href' => '/cotizaciones?tender_id='.$tender->id, 'hint' => 'Al presentar la cotización, la licitación pasa a «Presentada».'];
            }

            return null;
        }

        if ($opportunity === null) {
            return ($can['createOpportunity'] ?? false)
                ? ['label' => 'Crear oportunidad', 'icon' => 'briefcase-plus-outline', 'action' => 'post', 'href' => route('tenders.opportunity', $tender), 'hint' => 'Los participantes y el resultado se registran en la oportunidad del pipeline.']
                : null;
        }

        if ($participants === 0) {
            return ['label' => 'Registrar participantes', 'icon' => 'account-group-outline', 'action' => 'href', 'href' => "/pipeline/{$opportunity->id}", 'hint' => 'Anota quién más ofertó y por cuánto (acta de apertura).'];
        }

        return ['label' => 'Registrar resultado', 'icon' => 'flag-checkered', 'action' => 'href', 'href' => "/pipeline/{$opportunity->id}", 'hint' => $awaiting ? 'La apertura ya pasó: registra si se adjudicó o no.' : 'Cuando se publique el resultado, regístralo como ganada o perdida.'];
    }
}
