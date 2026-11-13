<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['institution_id' => ['nullable', 'exists:institutions,id'], 'name' => ['required', 'string', 'max:255'], 'tax_id' => ['nullable', 'string', 'max:50', Rule::unique('clients', 'tax_id')->ignore($this->route('client'))], 'type' => ['nullable', 'string', 'max:100'], 'sector' => ['nullable', 'string', 'max:100'], 'contact_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'], 'status' => ['required', 'in:Activo,Inactivo'], 'accumulated_amount' => ['nullable', 'numeric', 'min:0'], 'last_purchase_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:5000']];
    }
}
