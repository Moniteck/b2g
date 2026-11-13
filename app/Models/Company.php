<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'tax_id', 'representative', 'contact_name', 'contact_email',
        'contact_phone', 'logo_path', 'is_active', 'settings', 'blessing_base_url',
        'blessing_token', 'blessing_price_list', 'tax_rate', 'prices_include_tax',
    ];

    /** El token de Blessing nunca sale hacia el navegador ni en `toArray()`. */
    protected $hidden = ['blessing_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
            'blessing_token' => 'encrypted',
            'tax_rate' => 'decimal:2',
            'prices_include_tax' => 'boolean',
        ];
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(Tender::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function hasBlessingToken(): bool
    {
        return filled($this->getRawOriginal('blessing_token'));
    }

    /**
     * Últimos cuatro caracteres del token, para mostrarlo enmascarado («••••a1b2»).
     */
    public function maskedBlessingToken(): ?string
    {
        if (! $this->hasBlessingToken()) {
            return null;
        }

        try {
            $token = (string) $this->blessing_token;
        } catch (\Throwable) {
            return '••••';
        }

        return '••••'.mb_substr($token, -4);
    }

    /**
     * Guarda el logo en el disco privado y borra el anterior.
     */
    public function storeLogo(UploadedFile $file): void
    {
        $previous = $this->logo_path;
        $path = $file->store("companies/{$this->id}/logo", 'local');

        $settings = $this->settings ?? [];
        unset($settings['logo']);

        $this->forceFill(['logo_path' => $path, 'settings' => $settings === [] ? null : $settings])->save();

        if ($previous && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }
    }

    public function deleteLogo(): void
    {
        if ($this->logo_path) {
            Storage::disk('local')->delete($this->logo_path);
        }

        $this->forceFill(['logo_path' => null])->save();
    }

    /**
     * URL autorizada del logo (o null), con versión para no servir uno viejo de caché.
     */
    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return route('companies.logo', $this->id).'?v='.($this->updated_at?->timestamp ?? 0);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'is_default')->withTimestamps();
    }
}
