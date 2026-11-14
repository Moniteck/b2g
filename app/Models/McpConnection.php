<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Acceso que un usuario concedió a un cliente MCP (ChatGPT, Claude…).
 *
 * Hay una por usuario y cliente OAuth. Nace al aprobar la pantalla de consentimiento
 * (`McpConsentController`) con las empresas y el nivel elegidos, y el middleware
 * `ApplyMcpConnection` la identifica por el cliente del token en cada petición.
 * Revocarla invalida todos los tokens de ese cliente para ese usuario.
 */
class McpConnection extends Model
{
    public const SCOPE_READ = 'mcp:read';

    public const SCOPE_WRITE = 'mcp:write';

    public const LEVEL_READ = 'read';

    public const LEVEL_WRITE = 'write';

    public const LEVELS = [
        self::LEVEL_READ => 'Solo lectura',
        self::LEVEL_WRITE => 'Lectura y escritura',
    ];

    protected $fillable = [
        'user_id', 'name', 'client_id', 'client_name', 'company_ids', 'scopes',
        'authorized_at', 'expires_at', 'last_used_at', 'revoked_at', 'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'company_ids' => 'array',
            'scopes' => 'array',
            'authorized_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** @return array<int, string> */
    public static function scopesFor(string $level): array
    {
        return $level === self::LEVEL_WRITE ? [self::SCOPE_READ, self::SCOPE_WRITE] : [self::SCOPE_READ];
    }

    public function level(): string
    {
        return $this->allowsScope(self::SCOPE_WRITE) ? self::LEVEL_WRITE : self::LEVEL_READ;
    }

    public function allowsScope(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /** @return array<int, int> */
    public function companyIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->company_ids)));
    }

    public function allowsCompany(?int $companyId): bool
    {
        return $companyId !== null && in_array($companyId, $this->companyIds(), true);
    }

    /**
     * @param  Builder<McpConnection>  $query
     * @return Builder<McpConnection>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Revoca la conexión y todos los tokens, códigos y refrescos de su cliente para su usuario.
     */
    public function revoke(?User $by = null): void
    {
        DB::transaction(function () use ($by) {
            if ($this->revoked_at === null) {
                $this->forceFill(['revoked_at' => now(), 'revoked_by' => $by?->id])->save();
            }

            self::revokeTokens((int) $this->user_id, (string) $this->client_id);
        });
    }

    /**
     * Invalida los tokens de acceso, de refresco y los códigos pendientes de un usuario y cliente.
     */
    public static function revokeTokens(int $userId, string $clientId): void
    {
        if ($clientId === '') {
            return;
        }

        $tokens = Passport::token()->newQuery()->where('user_id', $userId)->where('client_id', $clientId);
        $tokenIds = (clone $tokens)->pluck('id');

        $tokens->update(['revoked' => true]);

        if ($tokenIds->isNotEmpty()) {
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        }

        Passport::authCode()->newQuery()->where('user_id', $userId)->where('client_id', $clientId)->update(['revoked' => true]);
    }
}
