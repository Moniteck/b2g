<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Solicitud de acceso pública (sin sesión). El campo `website` es una trampa para robots:
 * va oculto en el formulario y una persona nunca lo llena.
 */
class StoreAccessRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Los errores vuelven a la pantalla de solicitud, nunca a la cabecera Referer. */
    protected $redirectRoute = 'access-request.create';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{7,40}$/'],
            'company' => ['nullable', 'string', 'max:40'],
            'company_name' => ['nullable', 'string', 'max:160', 'required_without:company'],
            'position' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe tu nombre completo.',
            'name.min' => 'Escribe tu nombre completo.',
            'name.max' => 'El nombre es demasiado largo.',
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'El correo no tiene un formato válido.',
            'email.max' => 'El correo es demasiado largo.',
            'phone.regex' => 'Escribe solo números, espacios y los signos + ( ) -.',
            'phone.max' => 'El teléfono es demasiado largo.',
            'company_name.required_without' => 'Elige tu empresa o escribe su nombre.',
            'company_name.max' => 'El nombre de la empresa es demasiado largo.',
            'position.required' => 'Escribe tu cargo.',
            'position.min' => 'Escribe tu cargo.',
            'position.max' => 'El cargo es demasiado largo.',
            'password.required' => 'Elige una contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'password.letters' => 'La contraseña debe incluir al menos una letra.',
            'password.numbers' => 'La contraseña debe incluir al menos un número.',
            'password.uncompromised' => 'Esta contraseña apareció en una filtración de datos. Elige otra.',
        ];
    }
}
