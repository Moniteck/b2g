<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Documento con versiones inmutables.
 *
 * Los de `kind = empresa` forman el Directorio Institucional (RTN, solvencias…); el resto son
 * adjuntos de una entidad (cotización, contrato, licitación…) enlazados con `document_links`.
 *
 * Estado: se calcula con `expires_at` (Vigente, Por vencer ≤ 30 días, Vencido, Sin vencimiento)
 * y se guarda en `status` al guardar, con los eventos normales de Eloquent, para que los
 * observadores (calendario y recordatorios) reaccionen al vencimiento.
 */
class Document extends Model
{
    use BelongsToCompany, HasFactory;

    /** Días antes del vencimiento en que el documento pasa a «Por vencer». */
    public const EXPIRING_DAYS = 30;

    public const STATUS_VALID = 'Vigente';

    public const STATUS_EXPIRING = 'Por vencer';

    public const STATUS_EXPIRED = 'Vencido';

    public const STATUS_NO_EXPIRY = 'Sin vencimiento';

    /** Clave del filtro => estado. */
    public const STATUSES = [
        'vigente' => self::STATUS_VALID,
        'por_vencer' => self::STATUS_EXPIRING,
        'vencido' => self::STATUS_EXPIRED,
        'sin_vencimiento' => self::STATUS_NO_EXPIRY,
    ];

    /** Tipo de expediente documental. */
    public const KINDS = [
        'empresa' => 'Documento de empresa',
        'institucion' => 'Documento de institución',
        'proceso' => 'Documento de proceso',
        'contrato' => 'Documento de contrato',
        'cliente' => 'Documento de cliente',
    ];

    /** Categorías del Directorio Institucional (documentos de la empresa). */
    public const CATEGORIES = [
        'rtn' => 'RTN',
        'dni' => 'DNI del representante',
        'solvencia_sar' => 'Solvencia fiscal SAR',
        'solvencia_municipal' => 'Solvencia municipal',
        'ihss' => 'IHSS',
        'infop' => 'INFOP',
        'rap' => 'RAP',
        'oncae' => 'Constancia ONCAE',
        'escritura' => 'Escritura de constitución',
        'poder' => 'Poder de representación',
        'permiso_operacion' => 'Permiso de operación',
        'otro' => 'Otros',
    ];

    protected $fillable = [
        'company_id', 'institution_id', 'name', 'reference_number', 'description', 'kind', 'owner_name',
        'required_for', 'category', 'version', 'hash', 'path', 'issued_at',
        'expires_at', 'status', 'original_filename', 'disk', 'mime_type', 'size', 'uploaded_by',
        'signed_at', 'signed_by', 'sealed_at', 'renewal_owner_id',
    ];

    protected static function booted(): void
    {
        // El estado guardado siempre refleja la fecha de vencimiento.
        static::saving(function (Document $document): void {
            $document->status = $document->computedStatus();
        });
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'signed_at' => 'datetime',
            'sealed_at' => 'datetime',
            'version' => 'integer',
            'size' => 'integer',
        ];
    }

    /** Las fechas se guardan como «AAAA-MM-DD» en cualquier motor (comparables como texto). */
    protected function issuedAt(): Attribute
    {
        return Attribute::set(fn ($value) => self::dateOnly($value));
    }

    protected function expiresAt(): Attribute
    {
        return Attribute::set(fn ($value) => self::dateOnly($value));
    }

    public static function dateOnly(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toDateString();
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    /** Versión vigente: la de número más alto. */
    public function currentVersion()
    {
        return $this->hasOne(DocumentVersion::class)->ofMany('version', 'max');
    }

    public function links()
    {
        return $this->hasMany(DocumentLink::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /** Vencido: la fecha de vencimiento ya pasó (el mismo día aún es vigente). */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(Carbon::today());
    }

    public function computedStatus(): string
    {
        if ($this->expires_at === null) {
            return self::STATUS_NO_EXPIRY;
        }

        $today = Carbon::today();

        if ($this->expires_at->lt($today)) {
            return self::STATUS_EXPIRED;
        }

        return $this->expires_at->lte($today->copy()->addDays(self::EXPIRING_DAYS))
            ? self::STATUS_EXPIRING
            : self::STATUS_VALID;
    }

    /** Días hasta el vencimiento (negativo si ya venció), o null sin fecha. */
    public function daysToExpire(): ?int
    {
        return $this->expires_at === null ? null : (int) Carbon::today()->diffInDays($this->expires_at, false);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? (string) $this->kind;
    }

    public function categoryLabel(): ?string
    {
        return $this->category === null ? null : (self::CATEGORIES[$this->category] ?? $this->category);
    }

    /**
     * Filtra por estado calculado con la fecha de hoy (clave de `STATUSES`).
     *
     * @param  Builder<Document>  $query
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        $today = Carbon::today()->toDateString();
        $limit = Carbon::today()->addDays(self::EXPIRING_DAYS)->toDateString();

        return match ($status) {
            'vencido' => $query->where('expires_at', '<', $today),
            'por_vencer' => $query->where('expires_at', '>=', $today)->where('expires_at', '<=', $limit),
            'vigente' => $query->where('expires_at', '>', $limit),
            'sin_vencimiento' => $query->whereNull('expires_at'),
            default => $query,
        };
    }

    /**
     * Documentos del Directorio Institucional.
     *
     * @param  Builder<Document>  $query
     */
    public function scopeDirectory(Builder $query): Builder
    {
        return $query->where('kind', 'empresa');
    }
}
