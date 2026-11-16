<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Base64File;
use App\Mcp\Support\Present;
use App\Mcp\Support\ToolError;
use App\Models\Tender;
use App\Models\User;
use App\Services\Ai\OpenAIService;
use App\Services\Documents\FileTypes;
use App\Services\Documents\UploadInspector;
use App\Services\Tenders\BidReviewService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('upload_bid_document')]
#[Title('Subir pliego de condiciones')]
#[Description('Sube el PDF del pliego de una licitación (base64, máx. 10 MB por MCP; los más grandes se suben desde el CRM web), lo guarda como documento de la licitación y pone en cola su análisis con IA. Devuelve el análisis en estado «En cola»: consulte get_bid_analysis hasta que esté «Listo para revisar». Requiere que la IA esté configurada.')]
#[IsDestructive(false)]
class UploadBidDocumentTool extends BaseTool
{
    protected ?array $record = ['tender_id', Tender::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'tender_id' => $schema->integer()->description('Id de la licitación.')->required(),
            ...$this->companySchema($schema),
            'content_base64' => $schema->string()->description('PDF del pliego en base64.')->required(),
            'file_name' => $schema->string()->description('Nombre del archivo, p. ej. «pliego-LPN-007.pdf».')->required(),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $tender = $this->find(Tender::class, $request->get('tender_id'), 'la licitación');
        $this->authorize('update', $tender);
        $this->authorize('use-ai');

        if (! app(OpenAIService::class)->isConfigured()) {
            throw new ToolError('El análisis con IA no está configurado en el servidor. Sube el pliego como documento (add_attachment) y completa las fechas a mano con update_tender.');
        }

        $file = Base64File::decode($request->get('content_base64'), $request->get('file_name'));
        app(UploadInspector::class)->inspect($file, 'file', FileTypes::PDF_ONLY);

        $extraction = app(BidReviewService::class)->start($tender, $file, $user);

        return $this->ok([
            'analysis' => Present::analysis($extraction->fresh()),
            'next_step' => 'El análisis corre en segundo plano (puede tardar unos minutos). Consulta get_bid_analysis con tender_id y analysis_id.',
        ], 'Pliego recibido. El análisis quedó en cola.');
    }
}
