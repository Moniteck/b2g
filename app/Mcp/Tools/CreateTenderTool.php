<?php

namespace App\Mcp\Tools;

use App\Http\Requests\TenderRequest;
use App\Mcp\Support\Present;
use App\Mcp\Tools\Concerns\DescribesTenderFields;
use App\Models\Tender;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tenders\TenderAttributes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_tender')]
#[Title('Crear licitación')]
#[Description('Crea una licitación a mano con los mismos campos y reglas del formulario web. Sus fechas pasan solas al calendario con sus recordatorios. Úsela solo con datos que la persona confirmó; para una captura de HonduCompras use import_tender_capture.')]
#[IsDestructive(false)]
class CreateTenderTool extends BaseTool
{
    use DescribesTenderFields;

    public function schema(JsonSchema $schema): array
    {
        $fields = $this->tenderFieldSchema($schema);
        $fields['reference'] = $fields['reference']->required();
        $fields['title'] = $fields['title']->required();

        return [...$this->companySchema($schema), ...$fields];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('create', Tender::class);

        $input = $this->normalizeTender($request->all()) + ['status' => 'Activa', 'estimated_amount' => 0, 'offer_submitted' => false];
        $data = $this->validate($input, $this->tenderRules($companyId), TenderRequest::fieldMessages(), TenderRequest::fieldAttributes());

        $tender = Tender::create(TenderAttributes::fromInput($data) + ['company_id' => $companyId, 'owner_id' => $user->id]);

        app(AuditLogger::class)->record('licitacion.creada', $tender, ['expediente' => $tender->reference]);

        return $this->ok(['tender' => Present::tender($tender->fresh(['institution:id,name', 'owner:id,name']))], "Licitación {$tender->reference} creada.");
    }
}
