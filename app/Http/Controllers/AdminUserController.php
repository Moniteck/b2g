<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage-settings');
        return Inertia::render('ModulePage', ['module' => 'configuracion', 'config' => ['title' => 'Usuarios y Roles', 'icon' => '⚙️', 'action' => '', 'columns' => ['Usuario', 'Correo', 'Rol'], 'kind' => 'table'], 'records' => User::orderBy('name')->get()->map(fn (User $user) => ['id' => $user->id, 'values' => [$user->name, $user->email, $user->role]])]);
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-settings');
        $role = $request->validate(['role' => ['required', 'in:admin,operator']])['role'];
        if ($user->role === 'admin' && $role !== 'admin' && User::where('role', 'admin')->count() === 1) abort(422, 'No se puede degradar al último administrador.');
        $user->update(['role' => $role]);
        return back()->with('success', 'Rol actualizado.');
    }
}
