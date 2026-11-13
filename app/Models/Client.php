<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'institution_id', 'source_quote_id', 'origin', 'dedupe_key', 'name',
        'tax_id', 'type', 'sector', 'contact_name', 'contact_user_id', 'phone', 'email',
        'address', 'status', 'accumulated_amount', 'last_purchase_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'accumulated_amount' => 'decimal:2',
            'last_purchase_at' => 'date',
        ];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function invoices()
    {
        return $this->hasMany(CollectionInvoice::class);
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function sourceQuote()
    {
        return $this->belongsTo(Quote::class, 'source_quote_id');
    }

    /**
     * Clave de deduplicacion: evita crear dos clientes para la misma institucion
     * dentro de la misma empresa.
     */
    public static function dedupeKeyFor(int $companyId, ?int $institutionId, string $name): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));

        return implode('|', [$companyId, $institutionId ?? 'sin-institucion', $normalized]);
    }
}
