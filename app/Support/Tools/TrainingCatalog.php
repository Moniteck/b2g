<?php

namespace App\Support\Tools;

/**
 * Cursos de capacitación del CRM. Cada curso es una serie de lecciones que se
 * leen en orden; el progreso de cada usuario se guarda en `training_progress`.
 */
final class TrainingCatalog
{
    /**
     * @return array<int, array{key: string, title: string, description: string, icon: string, minutes: int, lessons: array<int, array{title: string, paragraphs: array<int, string>}>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'primeros-pasos',
                'title' => 'Primeros pasos en el CRM',
                'description' => 'Empresa activa, menú, permisos y cómo moverte por el sistema.',
                'icon' => 'compass-outline',
                'minutes' => 8,
                'lessons' => [
                    ['title' => 'La empresa activa', 'paragraphs' => [
                        'Todo lo que ves en las listas pertenece a la empresa activa, que eliges con el selector de la cabecera. Si trabajas para varias empresas del grupo, cambia de empresa antes de registrar algo.',
                        'Los catálogos compartidos (instituciones, contactos y competidores) son comunes a todo el grupo; el resto (licitaciones, cotizaciones, contratos, documentos) es de cada empresa.',
                    ]],
                    ['title' => 'Tu rol y lo que puedes hacer', 'paragraphs' => [
                        'Solo lectura consulta la información. Operador crea y edita registros y usa las herramientas de IA. Administrador además elimina y configura la empresa.',
                        'Si un botón no aparece, es porque tu rol no permite esa acción. Pide al administrador de tu empresa que revise tu acceso.',
                    ]],
                    ['title' => 'Avisos y recordatorios', 'paragraphs' => [
                        'La campana de la cabecera reúne los recordatorios de plazos: cierre de aclaraciones, presentación de ofertas, entregas y vencimientos de documentos.',
                        'Marca un plazo como atendido cuando ya no necesites más avisos de él.',
                    ]],
                ],
            ],
            [
                'key' => 'licitaciones',
                'title' => 'Licitaciones de punta a punta',
                'description' => 'De la captura de HonduCompras a la oferta presentada.',
                'icon' => 'gavel',
                'minutes' => 12,
                'lessons' => [
                    ['title' => 'Registrar la licitación', 'paragraphs' => [
                        'Sube la captura de la ficha del expediente de HonduCompras. El sistema lee el expediente, la entidad y las fechas; revisa cada dato antes de confirmar.',
                        'Ninguna fecha leída automáticamente se guarda sin tu confirmación: compara siempre con la ficha original.',
                    ]],
                    ['title' => 'El pliego y la matriz documental', 'paragraphs' => [
                        'Sube el pliego en la ficha de la licitación. El análisis propone fechas, documentos exigidos y productos, cada uno con su cita y página.',
                        'Con los documentos exigidos se arma la matriz documental: un requisito por fila, con su estado (cumple, pendiente o vencido) y la fecha de vencimiento del documento que lo respalda.',
                    ]],
                    ['title' => 'Fechas críticas', 'paragraphs' => [
                        'El cierre de aclaraciones es el último día para hacer preguntas a la institución. Después de esa fecha ya no se pueden pedir aclaraciones.',
                        'La presentación de ofertas y la apertura quedan en el calendario con sus recordatorios.',
                    ]],
                ],
            ],
            [
                'key' => 'cotizaciones',
                'title' => 'Cotizaciones y resultado',
                'description' => 'Armar la oferta, firmarla, registrar participantes y el resultado.',
                'icon' => 'file-document-edit-outline',
                'minutes' => 10,
                'lessons' => [
                    ['title' => 'Preparar la cotización', 'paragraphs' => [
                        'Crea la cotización desde la oportunidad o la licitación, agrega las líneas desde el inventario y revisa totales e impuesto.',
                        'Adjunta los PDF que acompañan la oferta. La versión firmada nunca sustituye al original: queda como un adjunto nuevo.',
                    ]],
                    ['title' => 'Participantes y resultado', 'paragraphs' => [
                        'Registra a los participantes con sus montos: así sabrás en qué posición quedó tu oferta y alimentarás la inteligencia competitiva.',
                        'Al marcar la oferta como Ganada se crean el cliente y el contrato; al marcarla Perdida se pide quién ganó y con qué monto.',
                    ]],
                ],
            ],
            [
                'key' => 'herramientas',
                'title' => 'Herramientas de apoyo',
                'description' => 'Agente Legal, generador de documentos, consorcios e historial.',
                'icon' => 'toolbox-outline',
                'minutes' => 9,
                'lessons' => [
                    ['title' => 'Agente Legal', 'paragraphs' => [
                        'El Agente Legal orienta sobre la Ley de Contratación del Estado y su reglamento. Sus respuestas son orientación general: no sustituyen la revisión de un abogado.',
                        'Desde la pestaña Escritos puedes preparar borradores de impugnaciones, recursos o solicitudes y exportarlos a PDF para revisarlos.',
                    ]],
                    ['title' => 'Generador de documentos', 'paragraphs' => [
                        'Elige el tipo de documento, completa destinatario y contexto, y el sistema redacta un borrador con el membrete de la empresa activa.',
                        'Revisa siempre el texto antes de firmarlo y presentarlo.',
                    ]],
                    ['title' => 'Consorcios e historial', 'paragraphs' => [
                        'Un consorcio agrupa empresas para una oferta conjunta. La participación de sus miembros debe sumar exactamente 100 %.',
                        'El historial de HonduCompras guarda los procesos en los que la empresa participó o decidió no participar, con su resultado y su causa.',
                    ]],
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    public static function find(string $key): ?array
    {
        return collect(self::all())->firstWhere('key', $key);
    }
}
