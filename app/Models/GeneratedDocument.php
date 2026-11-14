<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Documento redactado con IA desde el Generador de documentos, con el membrete
 * de la empresa activa en el momento de generarlo.
 */
class GeneratedDocument extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'user_id', 'type', 'title', 'recipient', 'content', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
