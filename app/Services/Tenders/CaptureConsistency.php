<?php

namespace App\Services\Tenders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Contradicciones en los datos de una captura (hallazgo B-18): no impiden importar, pero se
 * avisan en la revisión (la pantalla repite estas reglas en vivo, en `tenders.js`) y al confirmar.
 *
 *   CaptureConsistency::check(['clarification_deadline' => '2022-07-26 16:00', 'presentation_deadline' => '2022-07-25 16:00'])
 *   // [['field' => 'clarification_deadline', 'message' => 'El cierre de aclaraciones es posterior a la recepción de ofertas.']]
 */
final class CaptureConsistency
{
    /** Etapas de HonduCompras en las que el proceso ya terminó. */
    public const FINISHED_STAGES = ['Adjudicado', 'Desierto', 'Fracasado', 'Cancelado', 'Contratado', 'Terminado'];

    /**
     * @param  array<string, mixed>  $values  valores del formulario (o leídos)
     * @param  array<string, array<string, mixed>>  $fields  campos del OCR (para saber qué fue ilegible)
     * @return array<int, array{field: string, message: string, kind: string}>
     */
    public static function check(array $values, array $fields = [], ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $date = fn (string $key) => filled($values[$key] ?? null) ? self::parse((string) $values[$key]) : null;
        [$started, $clarification, $presentation] = [$date('started_at'), $date('clarification_deadline'), $date('presentation_deadline')];
        $warnings = [];

        if ($clarification && $presentation && $clarification->gt($presentation)) {
            $warnings[] = ['field' => 'clarification_deadline', 'message' => 'El cierre de aclaraciones es posterior a la recepción de ofertas. Revisa las dos fechas con la captura.'];
        }

        if ($started && $presentation && $presentation->lt($started)) {
            $warnings[] = ['field' => 'presentation_deadline', 'message' => 'La recepción de ofertas es anterior a la fecha de inicio del proceso.'];
        }

        if ($presentation && $presentation->lt($now)) {
            $warnings[] = ['field' => 'presentation_deadline', 'message' => 'La recepción de ofertas ya pasó ('.$presentation->format('d/m/Y').'). Si no presentaste oferta, la licitación quedará como «Vencida».', 'kind' => 'info'];
        } elseif ($clarification && $clarification->lt($now)) {
            $warnings[] = ['field' => 'clarification_deadline', 'message' => 'El plazo para enviar aclaraciones ya pasó.', 'kind' => 'info'];
        }

        $stage = trim((string) ($values['honducompras_stage'] ?? ''));
        $status = (string) ($values['status'] ?? 'Activa');
        if ($stage !== '' && in_array($stage, self::FINISHED_STAGES, true) && in_array($status, ['Activa', 'En preparación'], true)) {
            $warnings[] = ['field' => 'honducompras_stage', 'message' => "En HonduCompras la etapa es «{$stage}», pero la licitación se importará como «{$status}». Elige el estado que corresponde."];
        }

        $fee = $fields['bid_document_fee'] ?? null;
        if ($fee !== null && $fee['status'] !== HonduComprasCaptureParser::READ && blank($values['bid_document_fee'] ?? null)) {
            $warnings[] = ['field' => 'bid_document_fee', 'message' => 'El valor de los pliegos no se pudo leer. Escríbelo o déjalo vacío.'];
        }

        // «contradiction»: los datos no cuadran entre sí; «info»: dato correcto que conviene saber.
        return array_map(fn (array $warning) => $warning + ['kind' => 'contradiction'], $warnings);
    }

    /** Estado sugerido al importar según la etapa de HonduCompras y las fechas. */
    public static function suggestedStatus(array $values, ?CarbonInterface $now = null): string
    {
        $now ??= now();
        $stage = (string) ($values['honducompras_stage'] ?? '');

        return match (true) {
            in_array($stage, ['Desierto', 'Fracasado'], true) => 'Desierta',
            $stage === 'Cancelado' => 'Cancelada',
            filled($values['presentation_deadline'] ?? null) && ($at = self::parse((string) $values['presentation_deadline'])) && $at->lt($now) => TenderStage::STATUS_EXPIRED,
            default => 'Activa',
        };
    }

    private static function parse(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(str_replace('T', ' ', $value), 'America/Tegucigalpa');
        } catch (\Throwable) {
            return null;
        }
    }
}
