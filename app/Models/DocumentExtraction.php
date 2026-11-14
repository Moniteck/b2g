<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Lectura automática de un documento (captura con OCR o pliego con IA).
 *
 * `extracted` guarda la propuesta revisable (campos con valor, cita, página y
 * verificación); `pages` el texto por página; `usage` el consumo de la IA.
 * Nada de lo extraído se aplica a la licitación sin confirmación humana.
 */
class DocumentExtraction extends Model
{
    use BelongsToCompany;

    public const KIND_CAPTURE = 'captura';

    public const KIND_BID = 'pliego';

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const REVIEW = 'review';

    public const CONFIRMED = 'confirmed';

    public const FAILED = 'failed';

    public const STATUS_LABELS = [
        self::QUEUED => 'En cola',
        self::PROCESSING => 'Procesando',
        self::REVIEW => 'Listo para revisar',
        self::CONFIRMED => 'Confirmado',
        self::FAILED => 'Fallido',
    ];

    protected $fillable = [
        'company_id', 'tender_id', 'document_version_id', 'kind', 'status', 'extracted', 'evidence',
        'pages', 'usage', 'attempts', 'started_at', 'finished_at', 'confidence', 'method',
        'error', 'reviewed_at', 'reviewed_by',
    ];

    protected $hidden = ['pages'];

    protected function casts(): array
    {
        return [
            'extracted' => 'array',
            'evidence' => 'array',
            'pages' => 'array',
            'usage' => 'array',
            'attempts' => 'integer',
            'confidence' => 'decimal:2',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::QUEUED, self::PROCESSING], true);
    }
}
