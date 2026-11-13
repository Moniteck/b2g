<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'is_root'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
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

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Un administrador raiz opera sobre todas las empresas del grupo.
     */
    public function isRoot(): bool
    {
        return (bool) $this->is_root || $this->role === 'admin';
    }

    /**
     * Empresas que el usuario puede operar, sea por membresia o por ser raiz.
     */
    public function accessibleCompanyIds(): array
    {
        if ($this->isRoot()) {
            return Company::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->companies()->pluck('companies.id')->map(fn ($id) => (int) $id)->all();
    }

    public function canAccessCompany(?int $companyId): bool
    {
        if ($companyId === null) {
            return false;
        }

        if ($this->isRoot()) {
            return true;
        }

        return $this->companies()->where('companies.id', $companyId)->exists();
    }

    /**
     * Rol del usuario dentro de una empresa concreta.
     */
    public function companyRole(?int $companyId): ?string
    {
        if ($companyId === null) {
            return null;
        }

        if ($this->isRoot()) {
            return 'admin';
        }

        $membership = $this->companies()->where('companies.id', $companyId)->first();

        return $membership?->pivot?->role;
    }

    public function isCompanyAdmin(?int $companyId): bool
    {
        return in_array($this->companyRole($companyId), ['admin'], true);
    }

    public function defaultCompanyId(): ?int
    {
        if ($this->isRoot()) {
            return Company::query()->orderBy('name')->value('id');
        }

        $default = $this->companies()->wherePivot('is_default', true)->value('companies.id');

        return $default !== null ? (int) $default : $this->companies()->value('companies.id');
    }
}
