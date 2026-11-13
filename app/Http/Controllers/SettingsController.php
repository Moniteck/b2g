<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\SettingsRequest;
use App\Models\Company;
use App\Services\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración de la EMPRESA ACTIVA: datos de membrete, logo, vista previa de
 * PDF y restablecimiento. El sembrado de datos es solo por consola.
 */
class SettingsController extends Controller
{
    public function __construct(private CompanyContext $context) {}

    public function show(): Response
    {
        $this->authorize('manage-settings');

        $company = $this->company();

        return Inertia::render('Admin/Settings', [
            // `settings` conserva la forma histórica: columnas de la empresa + lo guardado en `companies.settings`.
            'settings' => [
                'name' => $company->name,
                'tax_id' => $company->tax_id,
                'representative' => $company->representative,
                ...collect($company->settings ?? [])->only(SettingsRequest::SETTINGS_KEYS)->all(),
            ],
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'tax_id' => $company->tax_id,
                'representative' => $company->representative,
                'contact_name' => $company->contact_name,
                'contact_email' => $company->contact_email,
                'contact_phone' => $company->contact_phone,
                'logo_url' => $company->logoUrl(),
                'tax_rate' => $company->tax_rate !== null ? (float) $company->tax_rate : 15.0,
                'prices_include_tax' => (bool) $company->prices_include_tax,
                'blessing_configured' => filled($company->blessing_base_url) && $company->hasBlessingToken(),
            ],
        ]);
    }

    public function update(SettingsRequest $request, AuditLogger $audit): RedirectResponse
    {
        $company = $this->company();
        $data = $request->safe()->except('settings');

        $settings = $company->settings ?? [];
        foreach ((array) $request->validated('settings', []) as $key => $value) {
            if (in_array($key, SettingsRequest::SETTINGS_KEYS, true)) {
                $settings[$key] = $value;
            }
        }

        $company->fill($data + ['settings' => $settings === [] ? null : $settings]);
        $changes = $company->getDirty();
        $company->save();

        if ($changes !== []) {
            $audit->record('configuracion.actualizada', $company, $changes, companyId: $company->id);
        }

        return back()->with('success', 'Configuración guardada.');
    }

    public function uploadLogo(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('manage-settings');

        $request->validate(
            ['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']],
            ['logo.required' => 'Elija una imagen.', 'logo.image' => 'El archivo no es una imagen.', 'logo.mimes' => 'Use PNG, JPG o WebP.', 'logo.max' => 'El logo no puede pasar de 2 MB.'],
        );

        $company = $this->company();
        $company->storeLogo($request->file('logo'));
        $audit->record('configuracion.logo', $company, [], companyId: $company->id);

        return back()->with('success', 'Logo actualizado.');
    }

    public function deleteLogo(AuditLogger $audit): RedirectResponse
    {
        $this->authorize('manage-settings');

        $company = $this->company();
        $company->deleteLogo();
        $audit->record('configuracion.logo_quitado', $company, [], companyId: $company->id);

        return back()->with('success', 'Logo quitado.');
    }

    /**
     * Vista previa del membrete con los datos guardados, en PDF.
     */
    public function preview(): HttpResponse
    {
        $this->authorize('manage-settings');

        $company = $this->company();
        $settings = $company->settings ?? [];
        $logo = null;

        if ($company->logo_path && Storage::disk('local')->exists($company->logo_path)) {
            $mime = Storage::disk('local')->mimeType($company->logo_path) ?: 'image/png';
            $logo = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($company->logo_path));
        }

        $html = view('pdf.company-preview', [
            'company' => $company,
            'settings' => $settings,
            'logo' => $logo,
            'colors' => [
                'surface' => config('design.chrome.surface'),
                'paperLine' => config('design.chrome.paperLine'),
                'brand' => config('design.brand.red'),
                'soft' => config('design.chrome.textSoft'),
            ],
        ])->render();

        return Pdf::loadHTML($html)->setPaper('letter')->stream('vista-previa.pdf');
    }

    /**
     * Zona de peligro: borra los datos de membrete y el logo. Exige escribir el
     * nombre exacto de la empresa.
     */
    public function reset(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('manage-settings');

        $company = $this->company();

        $request->validate(['confirmation' => ['required', 'string']], ['confirmation.required' => 'Escriba el nombre de la empresa para confirmar.']);

        if (trim((string) $request->input('confirmation')) !== $company->name) {
            return back()->withErrors(['confirmation' => 'El nombre no coincide. Escríbalo exactamente como aparece.']);
        }

        $company->deleteLogo();
        $company->forceFill(['settings' => null])->save();
        $audit->record('configuracion.restablecida', $company, [], companyId: $company->id);

        return back()->with('success', 'Configuración restablecida.');
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->context->currentCompanyId());
    }
}
