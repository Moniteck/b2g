<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Modo soporte»: la raíz del grupo opera una empresa de la que no es miembro sin crear una
 * membresía. La raíz ya puede acceder a todas las empresas (`User::accessibleCompanyIds`,
 * `CompanyContext`, `AllowedCompany`); esto solo cambia la empresa activa de la sesión, deja una
 * huella en la auditoría (de la empresa visitada) y le dice al marco que muestre la franja.
 *
 *   GET  /soporte/estado   { support, company, member_ids }  (el marco lo pide solo para la raíz;
 *                          una raíz sin membresías nunca está en modo soporte)
 *   POST /soporte/entrar   company_id                          → empresa activa + auditoría
 *   POST /soporte/salir                                        → vuelve a una empresa propia
 */
class SupportModeController extends Controller
{
    public function __construct(private CompanyContext $context) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberIds = array_keys($user->membershipRoles());
        $current = $this->context->currentCompany();

        return response()->json([
            // Una raíz sin membresías opera el grupo directamente: para ella no hay «modo soporte».
            'support' => $user->isRoot() && $memberIds !== [] && $current !== null && ! in_array($current['id'], $memberIds, true),
            'company' => $current,
            'member_ids' => $user->isRoot() ? $memberIds : null,
        ]);
    }

    public function enter(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isRoot(), 403, 'Solo la raíz del grupo puede entrar en modo soporte.');

        $data = $request->validate(
            ['company_id' => ['required', 'integer', 'exists:companies,id']],
            ['company_id.required' => 'Elija una empresa.', 'company_id.exists' => 'La empresa elegida no existe.'],
        );

        $company = Company::query()->findOrFail($data['company_id'], ['id', 'name']);
        $memberships = $request->user()->membershipRoles();
        $member = $memberships === [] || array_key_exists($company->id, $memberships);

        $request->session()->put(CompanyContext::SESSION_KEY, $company->id);
        $this->context->setCurrentCompanyId($company->id);

        if ($member) {
            return back()->with('success', "Empresa activa: {$company->name}.");
        }

        $audit->record('soporte.entrada', $company, ['empresa' => $company->name], companyId: $company->id);

        return back()->with('warning', "Modo soporte en {$company->name}. Lo que hagas queda en la auditoría de la empresa.");
    }

    public function leave(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $previous = $this->context->currentCompany();
        $memberIds = array_keys($user->membershipRoles());
        $target = $memberIds[0] ?? $user->defaultCompanyId();

        if ($previous !== null && $user->isRoot() && ! in_array($previous['id'], $memberIds, true)) {
            $audit->record('soporte.salida', null, ['empresa' => $previous['name']], companyId: $previous['id']);
        }

        if ($target !== null) {
            $request->session()->put(CompanyContext::SESSION_KEY, $target);
            $this->context->setCurrentCompanyId($target);
        }

        return back()->with('success', 'Saliste del modo soporte. Empresa activa: '.($this->context->currentCompany()['name'] ?? '').'.');
    }
}
