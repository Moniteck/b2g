<?php

namespace App\Http\Controllers;

use App\Http\Requests\Signatures\StoreSigningAssetRequest;
use App\Models\Document;
use App\Models\SignatureApplication;
use App\Models\SigningAsset;
use App\Services\AuditLogger;
use App\Services\Documents\SignatureService;
use App\Services\Tenancy\CompanyContext;
use App\Support\Binaries;
use App\Support\Permissions;
use App\Support\Tools\CompanyLetterhead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «Mi firma y sello»: firma y sello PERSONALES del usuario autenticado.
 *
 * Los activos se identifican por tipo (`firma` | `sello`) y siempre son los del
 * usuario autenticado: no hay forma de pedir la imagen de otra persona.
 */
class SigningAssetController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SigningAsset::class);

        $user = $request->user();

        $history = SignatureApplication::query()
            ->where('user_id', $user->id)
            ->latest('applied_at')
            ->latest('id')
            ->paginate(10, ['id', 'document_id', 'document_version_id', 'document_name', 'used_signature', 'used_seal', 'all_pages', 'pages', 'page_count', 'result_path', 'applied_at'])
            ->withQueryString()
            ->through(fn (SignatureApplication $application) => SignatureController::present($application));

        return Inertia::render('Signatures/Index', [
            'assets' => $this->assetsOf($request),
            'history' => $history,
            'lastPlacements' => SignatureController::lastPlacements($user),
            'company' => $this->stampCompany(),
            'can' => [
                'sign' => $user->hasPermission(Permissions::SIGN_DOCUMENTS),
                'saveDocuments' => $user->can('create', Document::class),
                'browseDocuments' => $user->can('viewAny', Document::class),
            ],
            'limits' => [
                'imageMb' => (int) (SigningAsset::MAX_KILOBYTES / 1024),
                'pdfMb' => (int) floor(min(Binaries::maxPdfKilobytes(), 25 * 1024) / 1024),
            ],
        ]);
    }

    /**
     * Estado para la modal de firma: mis activos, mi última posición y, con
     * `document_id`, si ya firmé ese documento.
     */
    public function state(Request $request, SignatureService $signatures): JsonResponse
    {
        $this->authorize('viewAny', SigningAsset::class);

        $user = $request->user();
        $signed = null;

        if ($request->filled('document_id')) {
            $document = Document::query()->find($request->integer('document_id'));

            if ($document !== null && $user->can('view', $document)) {
                $last = SignatureApplication::query()->withoutGlobalScopes()
                    ->where('document_id', $document->id)->where('user_id', $user->id)
                    ->latest('applied_at')->value('applied_at');

                $signed = ['already_signed' => $last !== null, 'last_signed_at' => $last ? (string) $last : null];
            }
        }

        return response()->json([
            'assets' => $this->assetsOf($request),
            'last_placements' => SignatureController::lastPlacements($user),
            'can_sign' => $user->hasPermission(Permissions::SIGN_DOCUMENTS),
            'company' => $this->stampCompany(),
            'document' => $signed,
        ]);
    }

    /**
     * Sube o reemplaza mi firma o mi sello. El archivo anterior se borra.
     */
    public function store(StoreSigningAssetRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->authorize('create', SigningAsset::class);

        $user = $request->user();
        $type = $request->validated('type');
        $file = $request->file('file');
        $info = @getimagesize($file->getRealPath());

        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return $this->fail($request, 'file', 'La imagen debe ser PNG o JPG.');
        }

        $extension = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $path = $file->storeAs('signing/'.$user->id, $type.'-'.Str::uuid().'.'.$extension, 'local');

        try {
            [$asset, $previous] = DB::transaction(function () use ($user, $type, $path, $file, $info, $extension) {
                $existing = SigningAsset::query()->where('user_id', $user->id)->where('type', $type)->lockForUpdate()->first();
                $previous = $existing?->only(['path', 'disk']);

                $asset = $existing ?? new SigningAsset(['user_id' => $user->id, 'type' => $type]);
                $asset->fill([
                    'name' => Str::limit($file->getClientOriginalName() ?: SigningAsset::TYPES[$type].'.'.$extension, 250, ''),
                    'path' => $path,
                    'disk' => 'local',
                    'mime_type' => $info['mime'],
                    'size' => $file->getSize(),
                    'width' => $info[0],
                    'height' => $info[1],
                    'is_active' => true,
                ]);
                $asset->updated_at = now();
                $asset->save();

                return [$asset, $previous];
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        if ($previous !== null && $previous['path'] !== $path) {
            Storage::disk($previous['disk'])->delete($previous['path']);
        }

        $audit->record($previous ? 'firma.activo.reemplazado' : 'firma.activo.subido', $asset, [
            'tipo' => $type,
            'ancho' => $asset->width,
            'alto' => $asset->height,
        ], $user, app(CompanyContext::class)->currentCompanyId());

        $message = [
            SigningAsset::TYPE_SIGNATURE => $previous ? 'Firma reemplazada.' : 'Firma guardada.',
            SigningAsset::TYPE_SEAL => $previous ? 'Sello reemplazado.' : 'Sello guardado.',
        ][$type];

        if ($this->wantsJson($request)) {
            return response()->json(['message' => $message, 'asset' => $this->presentAsset($asset), 'assets' => $this->assetsOf($request)]);
        }

        return back()->with('success', $message);
    }

    /**
     * Imagen de MI firma o MI sello (solo el dueño la recibe).
     */
    public function image(Request $request, string $type): HttpResponse
    {
        $asset = SigningAsset::query()->where('user_id', $request->user()->id)->where('type', $type)->first();

        abort_if($asset === null, 404);
        $this->authorize('view', $asset);

        $disk = Storage::disk($asset->disk);
        abort_unless($disk->exists($asset->path), 404);

        return response($disk->get($asset->path), 200, [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => 'inline; filename="'.$type.'.'.($asset->mime_type === 'image/png' ? 'png' : 'jpg').'"',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Quita mi firma o mi sello. El historial de firmas aplicadas se conserva.
     */
    public function destroy(Request $request, string $type, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $asset = SigningAsset::query()->where('user_id', $request->user()->id)->where('type', $type)->first();

        abort_if($asset === null, 404);
        $this->authorize('delete', $asset);

        $asset->delete();
        Storage::disk($asset->disk)->delete($asset->path);

        $audit->record('firma.activo.quitado', $asset, ['tipo' => $type], $request->user(), app(CompanyContext::class)->currentCompanyId());

        $message = $type === SigningAsset::TYPE_SEAL ? 'Sello quitado.' : 'Firma quitada.';

        if ($this->wantsJson($request)) {
            return response()->json(['message' => $message, 'assets' => $this->assetsOf($request)]);
        }

        return back()->with('success', $message);
    }

    /**
     * Nombre y RTN de la empresa activa: valores iniciales del generador de sellos.
     *
     * @return array{name: string, tax_id: string}
     */
    protected function stampCompany(): array
    {
        $company = CompanyLetterhead::current();

        return ['name' => (string) ($company['name'] ?? ''), 'tax_id' => (string) ($company['tax_id'] ?? '')];
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    protected function assetsOf(Request $request): array
    {
        $assets = SigningAsset::query()->where('user_id', $request->user()->id)->get()->keyBy('type');

        return collect(array_keys(SigningAsset::TYPES))
            ->mapWithKeys(fn (string $type) => [$type => $assets->has($type) ? $this->presentAsset($assets->get($type)) : null])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentAsset(SigningAsset $asset): array
    {
        return [
            'type' => $asset->type,
            'label' => $asset->typeLabel(),
            'name' => $asset->name,
            'url' => $asset->imageUrl(),
            'width' => $asset->width,
            'height' => $asset->height,
            'size' => $asset->size,
            'mime' => $asset->mime_type,
            'updated_at' => $asset->updated_at?->toIso8601String(),
        ];
    }

    protected function wantsJson(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }

    protected function fail(Request $request, string $field, string $message): JsonResponse|RedirectResponse
    {
        if ($this->wantsJson($request)) {
            return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
        }

        return back()->withErrors([$field => $message]);
    }
}
