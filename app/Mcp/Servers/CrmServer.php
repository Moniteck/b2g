<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ApplySignatureTool;
use App\Mcp\Tools\CreateQuoteTool;
use App\Mcp\Tools\GetDashboardTool;
use App\Mcp\Tools\GetQuoteTool;
use App\Mcp\Tools\GetTenderTool;
use App\Mcp\Tools\ListClientsTool;
use App\Mcp\Tools\ListCompaniesTool;
use App\Mcp\Tools\ListContractsTool;
use App\Mcp\Tools\ListDocumentsTool;
use App\Mcp\Tools\ListEventsTool;
use App\Mcp\Tools\ListOpportunitiesTool;
use App\Mcp\Tools\ListQuotesTool;
use App\Mcp\Tools\ListTendersTool;
use App\Mcp\Tools\RegisterQuoteOutcomeTool;
use App\Mcp\Tools\SearchInventoryTool;
use App\Mcp\Tools\SetParticipantsTool;
use App\Mcp\Tools\UpdateOpportunityStageTool;
use App\Mcp\Tools\UpdateQuoteTool;
use App\Mcp\Tools\UploadDocumentTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('CRM B2G')]
#[Version('1.0.0')]
#[Instructions('Servidor MCP del CRM B2G (contratacion publica de Honduras). Opera sobre empresas del grupo, cotizaciones, licitaciones, pipeline, clientes, documentos firmados, inventario, calendario y contratos post-adjudicacion. Reglas: toda operacion de escritura indica la empresa; las cotizaciones se mueven con estado documental (Borrador, Emitida, Presentada, Sustituida, Anulada) y resultado comercial separado (Pendiente, Ganada, Perdida); marcar una cotizacion como Ganada crea o reutiliza el cliente y abre el expediente post-adjudicacion, por lo que solo debe usarse cuando el usuario confirme que gano la oferta; los clientes no se crean de forma manual; las acciones sensibles requieren una autorizacion previa obtenida en el CRM.')]
class CrmServer extends Server
{
    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        ListCompaniesTool::class,
        ListQuotesTool::class,
        GetQuoteTool::class,
        CreateQuoteTool::class,
        UpdateQuoteTool::class,
        RegisterQuoteOutcomeTool::class,
        SetParticipantsTool::class,
        ListTendersTool::class,
        GetTenderTool::class,
        ListOpportunitiesTool::class,
        UpdateOpportunityStageTool::class,
        ListClientsTool::class,
        ListDocumentsTool::class,
        UploadDocumentTool::class,
        ApplySignatureTool::class,
        SearchInventoryTool::class,
        ListEventsTool::class,
        ListContractsTool::class,
        GetDashboardTool::class,
    ];
}
