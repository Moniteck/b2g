<?php

namespace App\Http\Controllers\Shell;

use App\Http\Controllers\Controller;
use App\Rules\AllowedCompany;
use App\Services\System\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Cambia la empresa activa del usuario (selector de la cabecera).
 *
 * POST /empresa-activa  { company_id }
 */
class ActiveCompanyController extends Controller
{
    public function __invoke(Request $request, CompanyContext $context, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', new AllowedCompany],
        ]);

        $companyId = (int) $data['company_id'];

        $request->session()->put(CompanyContext::SESSION_KEY, $companyId);
        $context->setCurrentCompanyId($companyId);
        $audit->record('sesion.empresa_cambiada', null, ['empresa' => $companyId], companyId: $companyId);

        return back()->with('success', 'Empresa activa: '.($context->currentCompany()['name'] ?? '').'.');
    }
}
