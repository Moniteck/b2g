<?php

namespace App\Support\Tools;

/**
 * Tipos de documento del Generador de documentos y de los escritos del Agente
 * Legal. Cada tipo trae las etiquetas y la ayuda de su formulario, para que el
 * usuario sepa qué escribir en cada campo.
 */
final class DocumentTypes
{
    /**
     * Documentos empresariales (Generador de documentos).
     *
     * @var array<string, array{label: string, description: string, recipient: string, context: string, hint: string}>
     */
    public const BUSINESS = [
        'carta_presentacion' => [
            'label' => 'Carta de presentación',
            'description' => 'Presenta la empresa ante una institución: giro, experiencia y datos de contacto.',
            'recipient' => 'Institución destinataria',
            'context' => 'Qué quieres destacar',
            'hint' => 'Productos o servicios que ofreces, experiencia con el Estado, cobertura y certificaciones.',
        ],
        'carta_interes' => [
            'label' => 'Carta de interés en un proceso',
            'description' => 'Manifiesta el interés de participar en una licitación o proceso de compra.',
            'recipient' => 'Institución que convoca',
            'context' => 'Proceso y motivo',
            'hint' => 'Número y nombre del proceso, lotes de interés y por qué la empresa puede cumplir.',
        ],
        'carta_compromiso' => [
            'label' => 'Carta de compromiso de entrega',
            'description' => 'Compromiso formal de plazos, lugar y condiciones de entrega.',
            'recipient' => 'Institución contratante',
            'context' => 'Compromisos',
            'hint' => 'Proceso, productos, plazo de entrega, lugar y condiciones que la empresa asume.',
        ],
        'declaracion_jurada' => [
            'label' => 'Declaración jurada',
            'description' => 'Declaración del representante legal exigida en los pliegos (inhabilidades, veracidad de datos).',
            'recipient' => 'Institución que la solicita',
            'context' => 'Qué debe declarar',
            'hint' => 'Copia el texto o el requisito del pliego que pide la declaración y el número de proceso.',
        ],
        'oficio_seguimiento' => [
            'label' => 'Oficio de seguimiento',
            'description' => 'Da seguimiento a una entrega, un pago o un trámite pendiente con la institución.',
            'recipient' => 'Institución destinataria',
            'context' => 'Asunto del seguimiento',
            'hint' => 'Contrato u orden de compra, fechas, montos y lo que se solicita a la institución.',
        ],
    ];

    /**
     * Escritos del Agente Legal.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const LEGAL = [
        'impugnacion' => ['label' => 'Borrador de impugnación', 'description' => 'Contra un acto del proceso (adjudicación, descalificación).'],
        'recurso_administrativo' => ['label' => 'Recurso administrativo', 'description' => 'Reposición o apelación ante la autoridad competente.'],
        'gestion_cobro' => ['label' => 'Gestión de cobro documentada', 'description' => 'Reclamo formal de pagos atrasados por bienes o servicios entregados.'],
        'solicitud_aclaracion' => ['label' => 'Solicitud de aclaración', 'description' => 'Consulta formal sobre el pliego dentro del plazo de aclaraciones.'],
        'modificacion_plazo' => ['label' => 'Solicitud de modificación de plazo', 'description' => 'Ampliación del plazo de entrega o del contrato, con su justificación.'],
        'respuesta_requerimiento' => ['label' => 'Respuesta a requerimiento', 'description' => 'Contesta un requerimiento o subsanación pedida por la institución.'],
    ];

    /** @return array<int, array{value: string, label: string, description: string, recipient: string, context: string, hint: string}> */
    public static function businessOptions(): array
    {
        return collect(self::BUSINESS)->map(fn (array $type, string $key) => ['value' => $key, ...$type])->values()->all();
    }

    /** @return array<int, array{value: string, label: string, description: string}> */
    public static function legalOptions(): array
    {
        return collect(self::LEGAL)->map(fn (array $type, string $key) => ['value' => $key, ...$type])->values()->all();
    }

    public static function businessLabel(string $type): string
    {
        return self::BUSINESS[$type]['label'] ?? $type;
    }

    public static function legalLabel(string $type): string
    {
        return self::LEGAL[$type]['label'] ?? $type;
    }
}
