<?php

namespace App\Http\Requests\Tools;

use App\Models\Institution;
use App\Models\Tender;
use App\Rules\AllowedCompany;
use App\Rules\BelongsToActiveCompany;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Consorcio con sus miembros. Si se envían miembros, su participación debe
 * sumar exactamente 100 %.
 */
class ConsortiumRequest extends FormRequest
{
    public const STATUSES = ['En formación', 'Activo', 'Disuelto'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->currentCompanyId();

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('consortia', 'name')->where('company_id', $companyId)->ignore($this->route('consortium'))],
            'tender_id' => ['nullable', 'integer', new BelongsToActiveCompany(Tender::class)],
            'institution_id' => ['nullable', 'integer', new BelongsToActiveCompany(Institution::class)],
            'leader_company_id' => ['nullable', 'integer', new AllowedCompany(Permissions::VIEW)],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'formed_at' => ['nullable', 'date'],
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'members' => ['nullable', 'array', 'max:20'],
            'members.*.id' => ['nullable', 'integer'],
            'members.*.name' => ['required', 'string', 'max:255'],
            'members.*.company_id' => ['nullable', 'integer', new AllowedCompany(Permissions::VIEW)],
            'members.*.tax_id' => ['nullable', 'string', 'max:100'],
            'members.*.role' => ['nullable', 'string', 'max:255'],
            'members.*.email' => ['nullable', 'email', 'max:255'],
            'members.*.phone' => ['nullable', 'string', 'max:50'],
            'members.*.participation_percentage' => ['required', 'numeric', 'gt:0', 'lte:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $members = collect((array) $this->input('members', []));

            if ($members->isEmpty() || $validator->errors()->has('members.*.participation_percentage')) {
                return;
            }

            $total = round($members->sum(fn ($member) => (float) ($member['participation_percentage'] ?? 0)), 2);

            if (abs($total - 100) > 0.001) {
                $shown = rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.');
                $validator->errors()->add('members', "La participación de los miembros debe sumar 100 %; ahora suma {$shown} %.");
            }

            $names = $members->map(fn ($member) => mb_strtolower(trim((string) ($member['name'] ?? ''))));
            if ($names->duplicates()->isNotEmpty()) {
                $validator->errors()->add('members', 'Hay miembros repetidos en la lista.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre', 'tender_id' => 'licitación', 'institution_id' => 'institución', 'leader_company_id' => 'empresa líder',
            'amount' => 'monto', 'formed_at' => 'fecha de constitución', 'status' => 'estado', 'notes' => 'notas',
            'members.*.name' => 'nombre del miembro', 'members.*.participation_percentage' => 'participación',
            'members.*.email' => 'correo', 'members.*.tax_id' => 'RTN', 'members.*.company_id' => 'empresa del grupo',
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Ya existe un consorcio con ese nombre en la empresa.'];
    }
}
