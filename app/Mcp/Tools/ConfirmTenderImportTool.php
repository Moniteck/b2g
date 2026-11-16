<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Tenders\CaptureConfirmRequest;
use App\Http\Requests\Tenders\TenderRequest;
use App\Mcp\Support\Present;
use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\DescribesTenderFields;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Tender;
use App\Models\User;
use App\Services\Tenders\CaptureImportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('confirm_tender_import')]
#[Title('Confirmar captura de HonduCompras')]
#[Description('Guarda la licitación revisada de una captura (token de import_tender_capture) con los datos que la persona confirmó. Exige recepción de ofertas o cierre de aclaraciones. Si el expediente ya existe, envía update_existing=true para completarlo en vez de duplicarlo. Por defecto crea su oportunidad en Prospecto. La imagen queda como documento de la licitación. Repetir la llamada no duplica nada.')]
#[IsDestructive(false)]
#[IsIdempotent]
class ConfirmTenderImportTool extends BaseTool
{
    use DescribesTenderFields;

    public function schema(JsonSchema $schema): array
    {
        $fields = $this->tenderFieldSchema($schema);
        unset($fields['status']);

        return [
            ...$this->companySchema($schema),
            'token' => $schema->string()->description('Token devuelto por import_tender_capture.')->required(),
            ...$fields,
            'new_institution_name' => $schema->string()->description('Si la institución no está en el catálogo: su nombre (se crea).'),
            'new_institution_acronym' => $schema->string()->description('Siglas de la institución nueva.'),
            'create_opportunity' => $schema->boolean()->description('Crear la oportunidad en Prospecto (por defecto true).'),
            'update_existing' => $schema->boolean()->description('Si el expediente ya existe, actualizarlo con la captura.'),
            'update_tender_id' => $schema->integer()->description('Licitación existente a actualizar (si el expediente se escribe distinto).'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('create', Tender::class);

        $token = (string) $request->get('token');
        if (! Str::isUuid($token)) {
            throw new ToolError('El token de la captura no es válido.');
        }

        $input = $this->normalizeTender($request->all());
        $input['create_opportunity'] = filter_var($input['create_opportunity'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $input['update_existing'] = filter_var($input['update_existing'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $input['offer_submitted'] = false;

        $data = $this->validate($input, (new CaptureConfirmRequest)->rules(), TenderRequest::fieldMessages() + [
            'new_institution_name.required_without' => 'Elige una institución del catálogo (institution_id) o indica new_institution_name.',
        ], TenderRequest::fieldAttributes());

        if (empty($data['clarification_deadline']) && empty($data['presentation_deadline'])) {
            throw new ToolError('Indica al menos la recepción de ofertas o el cierre de aclaraciones.');
        }

        if (empty($data['institution_id']) && filled($data['new_institution_name'] ?? null)) {
            $this->authorize('create', Institution::class);
        }

        if ($data['create_opportunity']) {
            $this->authorize('create', Opportunity::class);
        }

        $imports = app(CaptureImportService::class);
        $result = $imports->confirm($token, $imports->state($token, $user, $companyId), $data, $user, $companyId);
        $tender = $result['tender']->fresh(['institution:id,name', 'owner:id,name']);

        return $this->ok([
            'created' => $result['created'],
            'warning' => $result['warning'],
            'tender' => Present::tender($tender),
            'opportunity_id' => $tender->opportunities()->value('id'),
        ], $result['created'] ? "Licitación {$tender->reference} creada desde la captura." : "Licitación {$tender->reference} actualizada con la captura.");
    }
}
