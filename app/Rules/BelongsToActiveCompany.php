<?php

namespace App\Rules;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Services\Tenancy\CompanyContext;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * El id recibido existe y pertenece a la empresa sobre la que se opera.
 *
 * La empresa es la del campo `company_id` de la misma petición (si viene y el
 * actor tiene acceso a ella) o, en su defecto, la empresa activa.
 *
 * - Modelos con `BelongsToCompany`: su `company_id` debe coincidir.
 * - `User`: debe ser miembro de esa empresa (o raíz).
 * - Catálogos compartidos (Institution, Contact...): basta con que exista.
 *
 * Uso: 'tender_id' => ['nullable', 'integer', new BelongsToActiveCompany(Tender::class)]
 */
class BelongsToActiveCompany implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    protected array $data = [];

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(protected string $model, protected string $companyField = 'company_id') {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! $this->exists((int) $value)) {
            $fail('El valor seleccionado en :attribute no es válido.');
        }
    }

    protected function exists(int $id): bool
    {
        $companyId = $this->targetCompanyId();

        if ($this->model === User::class) {
            return User::query()->whereKey($id)
                ->where(fn ($query) => $query->where('is_root', true)->orWhereExists(
                    fn ($members) => $members->from('company_user')
                        ->whereColumn('company_user.user_id', 'users.id')
                        ->where('company_user.company_id', $companyId)
                ))->exists();
        }

        if (! in_array(BelongsToCompany::class, class_uses_recursive($this->model), true)) {
            return $this->model::query()->whereKey($id)->exists();
        }

        if ($companyId === null) {
            return false;
        }

        return $this->model::query()->withoutGlobalScope(CompanyScope::class)
            ->whereKey($id)->where((new $this->model)->qualifyColumn('company_id'), $companyId)->exists();
    }

    protected function targetCompanyId(): ?int
    {
        $context = app(CompanyContext::class);
        $requested = data_get($this->data, $this->companyField);

        if (is_numeric($requested) && $context->canAccess((int) $requested)) {
            return (int) $requested;
        }

        return $context->currentCompanyId();
    }
}
