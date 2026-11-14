<?php

namespace App\Models;

use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'is_root'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var array<int, string>|null Rol por empresa, cargado una sola vez por petición. */
    protected ?array $membershipRoles = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_root' => 'boolean',
        ];
    }

    public function opportunities()
    {
        return $this->hasMany(Opportunity::class, 'owner_id');
    }

    public function legalConversations()
    {
        return $this->hasMany(LegalConversation::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('role', 'is_default')->withTimestamps();
    }

    /**
     * Raíz del grupo: opera sobre todas las empresas. No es un rol de empresa;
     * la columna `users.role` es un resto del esquema anterior y ya no concede nada.
     */
    public function isRoot(): bool
    {
        return (bool) $this->is_root;
    }

    /**
     * Mapa empresa => rol de las membresías del usuario (la predeterminada primero).
     *
     * @return array<int, string>
     */
    public function membershipRoles(): array
    {
        return $this->membershipRoles ??= DB::table('company_user')
            ->where('user_id', $this->id)
            ->orderByDesc('is_default')
            ->orderBy('company_id')
            ->pluck('role', 'company_id')
            ->mapWithKeys(fn ($role, $companyId) => [(int) $companyId => (string) $role])
            ->all();
    }

    /**
     * Debe llamarse tras cambiar membresías del usuario en la misma petición.
     */
    public function forgetMemberships(): void
    {
        $this->membershipRoles = null;
    }

    /**
     * Empresas que el usuario puede operar, sea por membresía o por ser raíz.
     *
     * @return array<int, int>
     */
    public function accessibleCompanyIds(): array
    {
        if ($this->isRoot()) {
            return Company::query()->orderBy('name')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return array_keys($this->membershipRoles());
    }

    public function canAccessCompany(?int $companyId): bool
    {
        if ($companyId === null) {
            return false;
        }

        return $this->isRoot() || array_key_exists($companyId, $this->membershipRoles());
    }

    /**
     * Rol del usuario dentro de una empresa. La raíz actúa como administrador.
     */
    public function companyRole(?int $companyId): ?string
    {
        if ($companyId === null) {
            return null;
        }

        if ($this->isRoot()) {
            return Permissions::ROLE_ADMIN;
        }

        return $this->membershipRoles()[$companyId] ?? null;
    }

    public function isCompanyAdmin(?int $companyId): bool
    {
        return $this->companyRole($companyId) === Permissions::ROLE_ADMIN;
    }

    /**
     * Comprueba un permiso de la matriz en una empresa (por defecto, la activa).
     */
    public function hasPermission(string $permission, ?int $companyId = null): bool
    {
        if ($this->isRoot()) {
            return true;
        }

        $companyId ??= app(CompanyContext::class)->currentCompanyId();

        return Permissions::roleAllows($this->membershipRoles()[$companyId] ?? null, $permission);
    }

    public function initials(): string
    {
        return Str::of($this->name)->trim()->explode(' ')->filter()->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))->implode('');
    }

    public function defaultCompanyId(): ?int
    {
        if ($this->isRoot()) {
            $id = Company::query()->orderBy('name')->value('id');

            return $id !== null ? (int) $id : null;
        }

        return array_key_first($this->membershipRoles());
    }
}
