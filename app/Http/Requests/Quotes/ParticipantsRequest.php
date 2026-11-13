<?php

namespace App\Http\Requests\Quotes;

use Illuminate\Foundation\Http\FormRequest;

/** Lista completa de participantes del proceso; se guarda por diferencias. */
class ParticipantsRequest extends FormRequest
{
    use AuthorizesSubject;

    protected function abilities(): array
    {
        return ['quote' => 'manageParticipants', 'opportunity' => 'update'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'participants' => ['present', 'array', 'max:50'],
            'participants.*.id' => ['nullable', 'integer'],
            'participants.*.name' => ['required', 'string', 'max:255'],
            'participants.*.tax_id' => ['nullable', 'string', 'max:100'],
            'participants.*.amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'participants.*.currency' => ['nullable', 'in:HNL,USD'],
            'participants.*.includes_tax' => ['nullable', 'boolean'],
            'participants.*.is_ours' => ['nullable', 'boolean'],
            'participants.*.is_winner' => ['nullable', 'boolean'],
            'participants.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'participants.*.name' => 'nombre',
            'participants.*.amount' => 'monto',
            'participants.*.tax_id' => 'RTN',
            'participants.*.notes' => 'observaciones',
        ];
    }
}
