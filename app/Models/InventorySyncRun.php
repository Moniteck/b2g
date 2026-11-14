<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una corrida de sincronización del inventario con Blessing.
 */
class InventorySyncRun extends Model
{
    use BelongsToCompany;

    public const RUNNING = 'En curso';

    public const COMPLETED = 'Completada';

    /** Terminó, pero algunos productos no se pudieron guardar. */
    public const PARTIAL = 'Parcial';

    /** No cambió nada: se conserva el inventario anterior. */
    public const FAILED = 'Fallida';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULED = 'programada';

    public const TRIGGER_COMMAND = 'comando';

    public const TRIGGER_DEMO = 'demo';

    public const TRIGGER_LABELS = [
        self::TRIGGER_MANUAL => 'Manual',
        self::TRIGGER_SCHEDULED => 'Programada',
        self::TRIGGER_COMMAND => 'Consola',
        self::TRIGGER_DEMO => 'Demostración',
    ];

    protected $fillable = [
        'company_id', 'user_id', 'status', 'trigger', 'source', 'started_at', 'finished_at',
        'items_processed', 'created_count', 'updated_count', 'deactivated_count', 'error_count',
        'error', 'errors',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'items_processed' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'deactivated_count' => 'integer',
            'error_count' => 'integer',
            'errors' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function succeeded(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::PARTIAL], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'trigger' => self::TRIGGER_LABELS[$this->trigger] ?? $this->trigger,
            'source' => $this->source,
            'user' => $this->user?->name,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_seconds' => $this->started_at && $this->finished_at ? $this->started_at->diffInSeconds($this->finished_at) : null,
            'processed' => $this->items_processed,
            'created' => $this->created_count,
            'updated' => $this->updated_count,
            'deactivated' => $this->deactivated_count,
            'error_count' => $this->error_count,
            'error' => $this->error,
            'errors' => $this->errors ?? [],
        ];
    }
}
