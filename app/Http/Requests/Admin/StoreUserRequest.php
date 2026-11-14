<?php

namespace App\Http\Requests\Admin;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invitar o crear un usuario en una empresa. Si el correo ya existe, se agrega
 * la cuenta existente a la empresa sin tocar su contraseña.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(Permissions::ROLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escriba el nombre de la persona.',
            'email.required' => 'Escriba el correo.',
            'email.email' => 'El correo no tiene un formato válido.',
            'role.required' => 'Elija un rol.',
            'role.in' => 'El rol elegido no es válido.',
        ];
    }
}
