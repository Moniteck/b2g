<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administracion de las empresas del grupo. La cantidad de empresas es
 * dinamica: no existe un limite impuesto en el codigo.
 */
class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Company::class);

        $context = app(\App\Services\Tenancy\CompanyContext::class);

        $companies = Company::query()
            ->whereIn('id', $context->allowedCompanyIds())
            ->withCount(['quotes', 'opportunities', 'clients', 'contracts'])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company) => [
                'id' => (string) $company->id,
                'nombre' => $company->name,
                'codigo' => $company->code,
                'rtn' => $company->tax_id,
                'representante' => $company->representative,
                'contacto' => $company->contact_name,
                'correo' => $company->contact_email,
                'telefono' => $company->contact_phone,
                'activa' => $company->is_active,
                'blessing' => filled($company->blessing_base_url),
                'cotizaciones' => $company->quotes_count,
                'oportunidades' => $company->opportunities_count,
                'clientes' => $company->clients_count,
                'contratos' => $company->contracts_count,
                'miRol' => $request->user()->companyRole($company->id),
            ]);

        return Inertia::render('Companies', [
            'records' => $companies,
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role']),
            'canManage' => $request->user()->can('manage-companies'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Company::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:companies,code'],
            'tax_id' => ['nullable', 'string', 'max:100', 'unique:companies,tax_id'],
            'representative' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $company = Company::create($data + ['is_active' => $request->boolean('is_active', true)]);

        return back()->with('success', "Empresa {$company->name} creada.");
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $this->authorize('update', $company);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:companies,code,'.$company->id],
            'tax_id' => ['nullable', 'string', 'max:100', 'unique:companies,tax_id,'.$company->id],
            'representative' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'blessing_base_url' => ['nullable', 'string', 'max:255'],
            'blessing_token' => ['nullable', 'string', 'max:255'],
            'blessing_price_list' => ['nullable', 'string', 'max:100'],
        ]);

        $company->update($data);

        return back()->with('success', 'Empresa actualizada.');
    }

    public function destroy(Company $company): RedirectResponse
    {
        $this->authorize('delete', $company);

        if ($company->quotes()->exists() || $company->contracts()->exists()) {
            return back()->withErrors(['empresa' => 'La empresa tiene expedientes asociados. Desactivela en lugar de eliminarla.']);
        }

        $company->delete();

        return back()->with('success', 'Empresa eliminada.');
    }

    /**
     * Asigna un usuario a la empresa con su rol dentro del grupo.
     */
    public function member(Request $request, Company $company): RedirectResponse
    {
        $this->authorize('manageMembers', $company);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'in:admin,operator'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $company->users()->syncWithoutDetaching([
            $data['user_id'] => [
                'role' => $data['role'],
                'is_default' => $request->boolean('is_default'),
            ],
        ]);

        return back()->with('success', 'Membresía actualizada.');
    }

    public function removeMember(Company $company, User $user): RedirectResponse
    {
        $this->authorize('manageMembers', $company);

        $company->users()->detach($user->id);

        return back()->with('success', 'Membresía revocada.');
    }
}
