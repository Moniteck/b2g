<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Signatures\SignatureController;
use App\Mcp\Support\Present;
use App\Mcp\Support\ToolError;
use App\Mcp\Support\Untrusted;
use App\Models\Document;
use App\Models\SigningAsset;
use App\Models\User;
use App\Services\Documents\SignatureService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('sign_document')]
#[Title('Firmar y sellar un documento')]
#[Description('Estampa la firma y/o el sello PERSONALES del usuario dueño de la conexión (los que subió en «Mi firma y sello») sobre un PDF del sistema y guarda el resultado como versión nueva (el original se conserva). Nunca usa la firma de otra persona. Posiciones en % de la página, origen arriba a la izquierda; si no se indican, se usan las últimas que usó el usuario o, si no hay, abajo a la derecha de la página indicada. ACCIÓN SENSIBLE en dos pasos: la primera llamada devuelve un resumen y un confirmation_code; muéstralo a la persona y, solo si confirma, repite la llamada con los mismos argumentos y el código.')]
#[IsDestructive(false)]
class SignDocumentTool extends BaseTool
{
    protected ?array $record = ['document_id', Document::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()->description('Id del documento PDF (ver list_attachments o list_documents).')->required(),
            ...$this->companySchema($schema),
            'use_signature' => $schema->boolean()->description('Estampar la firma (por defecto true).'),
            'use_seal' => $schema->boolean()->description('Estampar el sello (por defecto true si el usuario tiene sello).'),
            'page' => $schema->integer()->description('Página donde se coloca (por defecto 1, o la de la última firma del usuario).'),
            'all_pages' => $schema->boolean()->description('Estampar en todas las páginas.'),
            'placements' => $schema->array()->description('Opcional: [{"type": "firma"|"sello", "page": 3, "x": 60, "y": 80, "width": 25, "height": 8}] en %.'),
            ...$this->confirmationSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $document = $this->find(Document::class, $request->get('document_id'), 'el documento');
        $this->authorize('view', $document);
        $this->authorize('sign', $document);

        $assets = SigningAsset::query()->withoutGlobalScopes()->where('user_id', $user->id)->pluck('type')->all();
        $useSignature = filter_var($request->get('use_signature', true), FILTER_VALIDATE_BOOLEAN);
        $useSeal = filter_var($request->get('use_seal', in_array(SigningAsset::TYPE_SEAL, $assets, true)), FILTER_VALIDATE_BOOLEAN);

        $data = $this->validate([
            'page' => $request->get('page'),
            'placements' => $request->get('placements'),
            'all_pages' => filter_var($request->get('all_pages', false), FILTER_VALIDATE_BOOLEAN),
        ], [
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'all_pages' => ['boolean'],
            'placements' => ['nullable', 'array', 'min:1', 'max:2'],
            'placements.*.type' => ['required', 'string', 'in:firma,sello', 'distinct'],
            'placements.*.page' => ['required', 'integer', 'min:1', 'max:10000'],
            'placements.*.x' => ['required', 'numeric', 'min:0', 'max:100'],
            'placements.*.y' => ['required', 'numeric', 'min:0', 'max:100'],
            'placements.*.width' => ['required', 'numeric', 'min:0.5', 'max:100'],
            'placements.*.height' => ['required', 'numeric', 'min:0.5', 'max:100'],
        ]);

        $placements = $this->placements($user, $data, $useSignature, $useSeal);
        $alreadySigned = app(SignatureService::class)->hasSigned($document, $user);

        $summary = [
            'action' => 'Firmar documento',
            'signer' => $user->name,
            'version_to_sign' => (int) $document->version,
            'use_signature' => $useSignature,
            'use_seal' => $useSeal,
            'all_pages' => $data['all_pages'],
            'placements' => $placements,
            'already_signed_by_you' => $alreadySigned,
            'missing_assets' => array_values(array_diff(array_keys(array_filter([SigningAsset::TYPE_SIGNATURE => $useSignature, SigningAsset::TYPE_SEAL => $useSeal])), $assets)),
            'effect' => 'Se creará una versión nueva firmada; la versión actual se conserva.',
            ...Untrusted::of(['document' => $document->name, 'file_name' => $document->original_filename], 255),
        ];

        if (! $useSignature && ! $useSeal) {
            throw new ToolError('Elige firma, sello o ambos.');
        }

        if ($summary['missing_assets'] !== []) {
            throw new ToolError('Aún no has subido tu '.implode(' ni tu ', array_map(fn ($type) => mb_strtolower(SigningAsset::TYPES[$type]), $summary['missing_assets'])).'. Súbelos en el CRM, en «Mi firma y sello» (/firmas), o usa use_seal=false / use_signature=false.');
        }

        // La versión forma parte de la acción: si el documento cambia entre los dos pasos, el código ya no sirve.
        return $this->confirmed($request, "sign_document:v{$document->version}", $document, $summary, function () use ($document, $user, $useSignature, $useSeal, $placements, $data) {
            $application = app(SignatureService::class)->signDocument($document, $user, [
                'use_signature' => $useSignature,
                'use_seal' => $useSeal,
                'placements' => $placements,
                'all_pages' => $data['all_pages'],
                'confirm' => true,
            ]);

            return $this->ok([
                'document' => Present::document($document->fresh()),
                'signature' => [
                    'id' => $application->id,
                    'pages' => $application->pages,
                    'page_count' => $application->page_count,
                    'applied_at' => Present::dt($application->applied_at),
                ],
            ], 'Documento firmado. Se guardó como una versión nueva; el original se conserva.');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function placements(User $user, array $data, bool $useSignature, bool $useSeal): array
    {
        $wanted = array_keys(array_filter([SigningAsset::TYPE_SIGNATURE => $useSignature, SigningAsset::TYPE_SEAL => $useSeal]));
        $given = collect($data['placements'] ?? SignatureController::lastPlacements($user))->keyBy('type');
        $page = $data['page'] ?? null;
        $defaults = [
            SigningAsset::TYPE_SIGNATURE => ['x' => 62, 'y' => 82, 'width' => 26, 'height' => 8],
            SigningAsset::TYPE_SEAL => ['x' => 40, 'y' => 78, 'width' => 16, 'height' => 12],
        ];

        return array_map(function (string $type) use ($given, $page, $defaults) {
            $placement = $given->get($type) ?? $defaults[$type];

            return [
                'type' => $type,
                'page' => (int) ($page ?? $placement['page'] ?? 1),
                'x' => (float) $placement['x'],
                'y' => (float) $placement['y'],
                'width' => (float) $placement['width'],
                'height' => (float) $placement['height'],
            ];
        }, $wanted);
    }
}
