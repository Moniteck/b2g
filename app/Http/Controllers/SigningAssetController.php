<?php

namespace App\Http\Controllers;

use App\Models\SigningAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Firma y sello de la empresa: imagenes privadas que se aplican sobre los
 * documentos. Los activos pueden revocarse, lo que impide su uso posterior.
 */
class SigningAssetController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SigningAsset::class);

        $context = app(\App\Services\Tenancy\CompanyContext::class);

        $assets = SigningAsset::query()
            ->whereIn('company_id', $context->allowedCompanyIds())
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (SigningAsset $asset) => [
                'id' => (string) $asset->id,
                'tipo' => $asset->type,
                'tipoNombre' => SigningAsset::TYPES[$asset->type] ?? $asset->type,
                'nombre' => $asset->name,
                'activo' => $asset->is_active,
                'revocada' => $asset->revoked_at !== null,
                'ancho' => $asset->width,
                'alto' => $asset->height,
                'subido_en' => $asset->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('SigningAssets', [
            'records' => $assets,
            'types' => SigningAsset::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('sign-documents');

        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'type' => ['required', 'in:'.implode(',', array_keys(SigningAsset::TYPES))],
            'name' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:4096', 'mimes:png,jpg,jpeg,webp'],
        ]);

        $file = $request->file('file');
        $path = $file->store('signing', 'local');

        [$width, $height] = $this->dimensions($file->getRealPath());

        $asset = SigningAsset::create([
            'company_id' => $data['company_id'] ?? app(\App\Services\Tenancy\CompanyContext::class)->currentCompanyId(),
            'type' => $data['type'],
            'name' => $data['name'],
            'path' => $path,
            'disk' => 'local',
            'mime_type' => $file->getMimeType(),
            'width' => $width,
            'height' => $height,
            'is_active' => true,
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Activo de firma cargado.');
    }

    public function update(Request $request, SigningAsset $asset): RedirectResponse
    {
        $this->authorize('sign-documents');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $asset->update($data);

        return back()->with('success', 'Activo actualizado.');
    }

    /**
     * Revoca el activo: deja de poder aplicarse a documentos nuevos.
     */
    public function revoke(SigningAsset $asset): RedirectResponse
    {
        $this->authorize('sign-documents');

        $asset->forceFill(['is_active' => false, 'revoked_at' => now()])->save();

        return back()->with('success', 'Activo revocado.');
    }

    public function destroy(SigningAsset $asset): RedirectResponse
    {
        $this->authorize('sign-documents');

        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();

        return back()->with('success', 'Activo eliminado.');
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(string $path): array
    {
        if (! function_exists('getimagesize')) {
            return [null, null];
        }

        $size = @getimagesize($path);

        return $size === false ? [null, null] : [(int) $size[0], (int) $size[1]];
    }
}
