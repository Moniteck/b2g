<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Firma o sello PERSONAL de un usuario: una imagen PNG o JPG en disco privado.
 *
 * Como máximo uno por usuario y tipo. Con un usuario autenticado, toda consulta
 * se limita a sus propios activos (nadie lista ni usa la firma de otro).
 */
class SigningAsset extends Model
{
    public const TYPE_SIGNATURE = 'firma';

    public const TYPE_SEAL = 'sello';

    public const TYPES = [self::TYPE_SIGNATURE => 'Firma', self::TYPE_SEAL => 'Sello'];

    /** Tamaño máximo de la imagen en kilobytes. */
    public const MAX_KILOBYTES = 2048;

    protected $fillable = [
        'user_id', 'type', 'name', 'path', 'disk', 'mime_type', 'size', 'width', 'height', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('owner', function (Builder $query): void {
            if (Auth::hasUser()) {
                $query->where($query->getModel()->qualifyColumn('user_id'), Auth::id());
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** URL autorizada de la imagen, con versión para no servir una copia vieja. */
    public function imageUrl(): string
    {
        return route('signatures.assets.image', ['type' => $this->type, 'v' => $this->updated_at?->timestamp]);
    }
}
