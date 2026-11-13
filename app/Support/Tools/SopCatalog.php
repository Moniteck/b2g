<?php

namespace App\Support\Tools;

/**
 * Procedimientos operativos estándar (SOP) del flujo B2G. Se leen en pantalla y
 * se descargan en PDF. La lectura de cada usuario se guarda en `training_progress`
 * con la clave `sop:<slug>`.
 */
final class SopCatalog
{
    public const PROGRESS_PREFIX = 'sop:';

    /**
     * @return array<int, array{slug: string, code: string, title: string, purpose: string, scope: string, owner: string, steps: array<int, array{title: string, detail: string}>, notes: array<int, string>}>
     */
    public static function all(): array
    {
        return [
            [
                'slug' => 'gestion-licitaciones',
                'code' => 'SOP-01',
                'title' => 'Gestión de licitaciones',
                'purpose' => 'Registrar cada proceso de compra pública con sus fechas críticas verificadas y decidir a tiempo si se participa.',
                'scope' => 'Licitaciones públicas y privadas, concursos y demás modalidades publicadas en HonduCompras.',
                'owner' => 'Operador comercial de la empresa',
                'steps' => [
                    ['title' => 'Registrar el proceso', 'detail' => 'Importar la captura de la ficha del expediente o crear la licitación a mano. Verificar expediente, entidad, objeto y modalidad.'],
                    ['title' => 'Confirmar las fechas', 'detail' => 'Comparar contra la ficha original el cierre de aclaraciones, la presentación y la apertura. Ninguna fecha se confirma sin revisarla.'],
                    ['title' => 'Analizar el pliego', 'detail' => 'Subir el pliego, revisar cada dato propuesto con su cita y página y crear la matriz documental con los documentos exigidos.'],
                    ['title' => 'Decidir la participación', 'detail' => 'Evaluar capacidad de entrega, márgenes y documentos vigentes. Registrar la decisión y, si no se participa, la causa en el historial.'],
                    ['title' => 'Preguntar a tiempo', 'detail' => 'Enviar las aclaraciones antes del cierre de aclaraciones. Después de esa fecha ya no se admiten consultas.'],
                ],
                'notes' => ['Los pliegos son documentos públicos; aun así, revisa lo que se envía al análisis automático.'],
            ],
            [
                'slug' => 'cotizaciones',
                'code' => 'SOP-02',
                'title' => 'Preparación y presentación de ofertas',
                'purpose' => 'Emitir ofertas completas, firmadas y presentadas dentro del plazo.',
                'scope' => 'Cotizaciones vinculadas a una oportunidad o a una licitación.',
                'owner' => 'Operador comercial y representante legal',
                'steps' => [
                    ['title' => 'Armar la cotización', 'detail' => 'Agregar líneas desde el inventario, validar existencias, precios, impuesto y vigencia de la oferta.'],
                    ['title' => 'Completar la matriz documental', 'detail' => 'Marcar cada requisito como cumple solo cuando el documento esté vigente y vinculado. Renovar los vencidos antes de presentar.'],
                    ['title' => 'Firmar y sellar', 'detail' => 'Firmar el PDF de la oferta con la firma y el sello personales. El original se conserva sin cambios.'],
                    ['title' => 'Presentar', 'detail' => 'Marcar la cotización como Presentada; la oportunidad pasa a Oferta Presentada.'],
                    ['title' => 'Registrar participantes', 'detail' => 'Después de la apertura, cargar a los participantes con sus montos y marcar cuál es nuestra oferta.'],
                ],
                'notes' => ['Una cotización solo se edita en estado Borrador o Emitida.'],
            ],
            [
                'slug' => 'documentos-empresa',
                'code' => 'SOP-03',
                'title' => 'Control de documentos de la empresa',
                'purpose' => 'Mantener vigentes los documentos legales y fiscales que piden las instituciones.',
                'scope' => 'RTN, solvencias, constancias, escrituras, poderes, permisos y demás documentos del directorio.',
                'owner' => 'Administrador de la empresa',
                'steps' => [
                    ['title' => 'Subir con fechas', 'detail' => 'Cargar cada documento con su fecha de emisión y de vencimiento en la categoría correcta.'],
                    ['title' => 'Atender los avisos', 'detail' => 'Los recordatorios avisan 30, 7 y 1 día antes del vencimiento. Iniciar el trámite de renovación con el primero.'],
                    ['title' => 'Renovar', 'detail' => 'Al renovar se sube el archivo nuevo con su nueva fecha; la versión anterior queda en el historial.'],
                    ['title' => 'Actualizar matrices', 'detail' => 'En la matriz documental de cada licitación vigente, renovar el requisito y vincular el documento nuevo.'],
                ],
                'notes' => ['Un documento vencido no debe incluirse en una oferta.'],
            ],
            [
                'slug' => 'post-adjudicacion',
                'code' => 'SOP-04',
                'title' => 'Seguimiento de contratos adjudicados',
                'purpose' => 'Cumplir entregas y plazos del contrato y cerrar con el acta de recepción.',
                'scope' => 'Contratos nacidos de ofertas ganadas.',
                'owner' => 'Oficial de seguimiento asignado al contrato',
                'steps' => [
                    ['title' => 'Completar el contrato', 'detail' => 'Registrar número, monto adjudicado, fecha de firma, plazo y desde qué hito corre.'],
                    ['title' => 'Planificar entregas', 'detail' => 'Crear cada entrega con su fecha comprometida; el calendario avisará antes de cada una.'],
                    ['title' => 'Documentar', 'detail' => 'Subir contrato, adendas, órdenes de compra, actas de recepción y facturas.'],
                    ['title' => 'Dar seguimiento', 'detail' => 'Anotar cada gestión con su próxima acción y fecha. Usar el oficio de seguimiento cuando haga falta un escrito formal.'],
                    ['title' => 'Cerrar', 'detail' => 'Con el producto entregado y el acta de recepción, cerrar el contrato.'],
                ],
                'notes' => ['Para reclamos de pago, el Agente Legal puede preparar una gestión de cobro documentada.'],
            ],
        ];
    }

    public static function find(string $slug): ?array
    {
        return collect(self::all())->firstWhere('slug', $slug);
    }

    /** @return array<int, string> */
    public static function slugs(): array
    {
        return array_column(self::all(), 'slug');
    }
}
