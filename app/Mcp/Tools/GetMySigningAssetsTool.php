<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Signatures\SignatureController;
use App\Models\SignatureApplication;
use App\Models\SigningAsset;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_my_signing_assets')]
#[Title('Mi firma y sello')]
#[Description('Si el usuario dueño de la conexión ya subió su firma y su sello personales, si su rol le permite firmar en la empresa, la última posición que usó y sus últimas firmas aplicadas. No devuelve las imágenes.')]
#[IsReadOnly]
#[IsIdempotent]
class GetMySigningAssetsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return $this->companySchema($schema);
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $assets = SigningAsset::query()->withoutGlobalScopes()->where('user_id', $user->id)->get(['type', 'updated_at'])->keyBy('type');

        return $this->ok([
            'signature' => ['uploaded' => $assets->has(SigningAsset::TYPE_SIGNATURE), 'updated_at' => $this->dateTime($assets->get(SigningAsset::TYPE_SIGNATURE)?->updated_at)],
            'seal' => ['uploaded' => $assets->has(SigningAsset::TYPE_SEAL), 'updated_at' => $this->dateTime($assets->get(SigningAsset::TYPE_SEAL)?->updated_at)],
            'can_sign_in_company' => $user->hasPermission(Permissions::SIGN_DOCUMENTS, $companyId),
            'last_placements' => SignatureController::lastPlacements($user),
            'recent_signatures' => SignatureApplication::query()->withoutGlobalScopes()
                ->where('user_id', $user->id)->where('company_id', $companyId)
                ->latest('applied_at')->limit(10)
                ->get(['id', 'document_id', 'document_name', 'pages', 'page_count', 'applied_at'])
                ->map(fn (SignatureApplication $application) => [
                    'id' => $application->id,
                    'document_id' => $application->document_id,
                    'pages' => $application->pages,
                    'page_count' => $application->page_count,
                    'applied_at' => $this->dateTime($application->applied_at),
                    ...$this->untrusted(['document' => $application->document_name], 255),
                ])->all(),
            'upload_hint' => 'La firma y el sello se suben solo desde el CRM web, en «Mi firma y sello» (/firmas).',
        ]);
    }
}
