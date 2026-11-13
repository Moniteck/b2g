<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Requisito de una matriz documental. `renewal_due_at` es la fecha en que vence
 * el documento que lo cumple; al renovarlo se cambia por la nueva.
 */
class ComplianceItem extends Model
{
    public const STATUS_COMPLIES = 'Cumple';

    public const STATUS_PENDING = 'Pendiente';

    public const STATUS_EXPIRED = 'Vencido';

    protected $fillable = ['compliance_matrix_id', 'document_id', 'requirement', 'completed', 'renewal_due_at', 'notes'];

    protected function casts(): array
    {
        return ['completed' => 'boolean', 'renewal_due_at' => 'date'];
    }

    public function matrix()
    {
        return $this->belongsTo(ComplianceMatrix::class, 'compliance_matrix_id');
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Cumple / Pendiente / Vencido. Un requisito cuya fecha ya pasó está vencido
     * aunque se hubiera marcado como cumplido: hay que renovarlo.
     */
    public function status(?Carbon $today = null): string
    {
        $today ??= today();

        if ($this->renewal_due_at !== null && $this->renewal_due_at->lt($today)) {
            return self::STATUS_EXPIRED;
        }

        return $this->completed ? self::STATUS_COMPLIES : self::STATUS_PENDING;
    }
}
