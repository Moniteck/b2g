<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usuarios y roles de la EMPRESA ACTIVA. El rol vive en `company_user.role`;
 * la condición de raíz solo se concede por consola (`php artisan crm:root`).
 */
class AdminUserController extends Controller
{
    public function index(CompanyContext $context): Response
    {
        $this->authorize('manage-users');

        $company = Company::query()->findOrFail($context->currentCompanyId());
        $members = $company->users()->orderBy('name')->get();

        return Inertia::render('ModulePage', [
            'module' => 'configuracion',
            'config' => ['title' => 'Usuarios y Roles', 'icon' => '⚙️', 'action' => '', 'columns' => ['Usuario', 'Correo', 'Rol'], 'kind' => 'table'],
            'records' => $members->map(fn (User $user) => [
                'id' => $user->id,
                'values' => [$user->name, $user->email, Permissions::ROLE_LABELS[$user->pivot->role] ?? $user->pivot->role],
                'role' => $user->pivot->role,
            ]),
            'roles' => Permissions::ROLE_LABELS,
        ]);
    }

    public function updateRole(Request $request, User $user, CompanyContext $context): RedirectResponse
    {
        $this->authorize('manage-users');

        $role = $request->validate(['role' => ['required', Rule::in(Permissions::ROLES)]])['role'];
        $company = Company::query()->findOrFail($context->currentCompanyId());
        $current = $company->users()->whereKey($user->id)->first()?->pivot->role;

        abort_if($current === null, 404);

        $isLastAdmin = $current === Permissions::ROLE_ADMIN
            && $role !== Permissions::ROLE_ADMIN
            && $company->users()->wherePivot('role', Permissions::ROLE_ADMIN)->count() === 1;

        if ($isLastAdmin) {
            return back()->withErrors(['role' => 'No se puede degradar al último administrador de la empresa.']);
        }

        $company->users()->updateExistingPivot($user->id, ['role' => $role]);

        return back()->with('success', 'Rol actualizado.');
    }
}
