<?php

namespace App\Mcp\Tools;

use App\Http\Requests\TenderRequest;
use App\Mcp\Support\Present;
use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\DescribesTenderFields;
use App\Models\Tender;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tenders\TenderAttributes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_tender')]
#[Title('Actualizar licitación')]
#[Description('Cambia solo los campos enviados de una licitación (mismas reglas que la web). Al cambiar una fecha, su evento del calendario se mueve y los recordatorios pendientes se reprograman. No cambia la empresa.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateTenderTool extends BaseTool
{
    use DescribesTenderFields;

    protected ?array $record = ['tender_id', Tender::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'tender_id' => $schema->integer()->description('Id de la licitación.')->required(),
            ...$this->companySchema($schema),
            ...$this->tenderFieldSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $tender = $this->find(Tender::class, $request->get('tender_id'), 'la licitación');
        $this->authorize('update', $tender);

        $changes = Arr::only($this->normalizeTender($request->all()), TenderAttributes::FIELDS);

        if ($changes === []) {
            throw new ToolError('Indica al menos un campo para actualizar.');
        }

        // Se valida el registro completo (reglas cruzadas de fechas) y se guardan solo los campos enviados.
        $current = $tender->only(TenderAttributes::FIELDS);
        foreach (['started_at', 'clarification_deadline', 'presentation_deadline', 'opening_at'] as $field) {
            $current[$field] = $tender->{$field}?->format('Y-m-d H:i:s');
        }
        $current['published_at'] = $tender->published_at?->format('Y-m-d');
        $current['closing_date'] = $tender->closing_date?->format('Y-m-d');

        $data = $this->validate($changes + $current, $this->tenderRules($companyId, $tender->id), TenderRequest::fieldMessages(), TenderRequest::fieldAttributes());
        $attributes = Arr::only(TenderAttributes::fromInput(Arr::only($data, array_keys($changes))), array_keys($changes));

        DB::transaction(function () use ($tender, $attributes) {
            $locked = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();
            $locked->fill($attributes)->save();
        });

        app(AuditLogger::class)->record('licitacion.actualizada', $tender, ['expediente' => $tender->reference, 'campos' => array_keys($attributes)]);

        return $this->ok(['tender' => Present::tender($tender->fresh(['institution:id,name', 'owner:id,name']))], 'Licitación actualizada.');
    }
}
