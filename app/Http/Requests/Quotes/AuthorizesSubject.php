<?php

namespace App\Http\Requests\Quotes;

use App\Models\Opportunity;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Model;

/**
 * Autoriza ANTES de validar: un usuario sin permiso recibe 403, no los errores del formulario.
 * El sujeto es la cotización o la oportunidad de la ruta; si llega como id (rutas compartidas sin
 * tipo en el controlador) se resuelve aquí con el scope de empresa, así que uno de otra empresa da 404.
 */
trait AuthorizesSubject
{
    /** Habilidad de la policy por parámetro de ruta: ['quote' => 'adjudicate', 'opportunity' => 'adjudicate']. */
    abstract protected function abilities(): array;

    public function authorize(): bool
    {
        foreach ($this->abilities() as $parameter => $ability) {
            $subject = $this->subjectFor($parameter);

            if ($subject !== null) {
                return (bool) $this->user()?->can($ability, $subject);
            }
        }

        return false;
    }

    protected function subjectFor(string $parameter): ?Model
    {
        $class = ['quote' => Quote::class, 'opportunity' => Opportunity::class][$parameter] ?? null;
        $value = $this->route($parameter);

        if ($class === null || $value === null) {
            return null;
        }

        if (! $value instanceof Model) {
            abort_unless(is_numeric($value), 404);
            $value = $class::query()->findOrFail((int) $value);
            $this->route()->setParameter($parameter, $value);
        }

        return $value;
    }
}
