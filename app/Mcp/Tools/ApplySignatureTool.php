<?php

namespace App\Mcp\Tools;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\McpAuthorization;
use App\Models\SigningAsset;
use App\Services\Documents\SignatureService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('apply_signature')]
#[Title('Firmar y sellar un documento')]
#[Description('Aplica la firma y/o el sello de la empresa sobre una version del documento y genera una version firmada. Es una accion sensible: requiere un `authorization_id` emitido por el CRM, valido para ese documento y ese contenido exacto. Si el documento cambio despues de la autorizacion, la operacion se rechaza y debe pedir una nueva autorizacion en el CRM.')]
class ApplySignatureTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'documento_id' => $schema->integer()->description('Documento a firmar.')->required(),
            'authorization_id' => $schema->integer()->description('Autorizacion emitida por el CRM para esta operacion.')->required(),
            'activo_id' => $schema->integer()->description('Activo de firma o sello a aplicar.')->required(),
            'pagina' => $schema->integer()->description('Numero de pagina donde se coloca la imagen. Por defecto 1.'),
            'x' => $schema->number()->description('Posicion horizontal en puntos, desde el margen izquierdo.'),
            'y' => $schema->number()->description('Posicion vertical en puntos, desde el borde superior.'),
            'ancho' => $schema->number()->description('Ancho en puntos de la imagen aplicada.'),
            'alto' => $schema->number()->description('Alto en puntos de la imagen aplicada.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $document = Document::query()->with('versions')->find((int) $request->get('documento_id'));

        if ($document === null) {
            return $this->fail('El documento indicado no existe.');
        }

        abort_unless($user->canAccessCompany($document->company_id), 403, 'No tiene acceso a este documento.');

        if (! $user->can('sign', $document)) {
            return $this->fail('No tiene permisos para firmar documentos.');
        }

        $asset = SigningAsset::query()->find((int) $request->get('activo_id'));

        if ($asset === null || ! $asset->isUsable()) {
            return $this->fail('El activo de firma o sello no existe o no esta activo.');
        }

        if ((int) $asset->company_id !== (int) $document->company_id) {
            return $this->fail('El activo de firma pertenece a otra empresa.');
        }

        $version = $document->versions()->orderByDesc('version')->first();

        if (! $version instanceof DocumentVersion) {
            return $this->fail('El documento no tiene ninguna version cargada.');
        }

        $authorization = McpAuthorization::query()
            ->where('action', 'firmar')
            ->where('resource_type', Document::class)
            ->where('resource_id', $document->id)
            ->find((int) $request->get('authorization_id'));

        if ($authorization === null) {
            return $this->fail('La autorizacion indicada no existe para este documento.');
        }

        if ((int) $authorization->user_id !== (int) $user->id) {
            return $this->fail('La autorizacion pertenece a otro usuario.');
        }

        if (! $authorization->isValid()) {
            return $this->fail('La autorizacion ya fue usada o vencio. Solicite una nueva autorizacion en el CRM.');
        }

        if ($authorization->payload_hash !== null && $authorization->payload_hash !== $version->hash) {
            return $this->fail('El documento cambio despues de la autorizacion. Solicite una nueva autorizacion en el CRM.');
        }

        $derived = app(SignatureService::class)->apply(
            $document,
            $version,
            $asset,
            [
                'page' => (int) $request->get('pagina', 1),
                'x' => (float) $request->get('x', 60),
                'y' => (float) $request->get('y', 700),
                'width' => (float) $request->get('ancho', 140),
                'height' => (float) $request->get('alto', 60),
            ],
            $user,
            $authorization->id,
        );

        $authorization->forceFill(['consumed_at' => now()])->save();

        return $this->ok([
            'documento_id' => $document->id,
            'version_firmada' => $derived->version,
            'hash' => $derived->hash,
        ], 'Documento firmado correctamente.');
    }
}
