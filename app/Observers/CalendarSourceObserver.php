<?php

namespace App\Observers;

use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractFollowUp;
use App\Models\Document;
use App\Models\Opportunity;
use App\Models\Tender;
use App\Services\Notifications\CalendarSyncService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Lleva al calendario los plazos de licitaciones, oportunidades, contratos, entregas,
 * seguimientos y documentos sin que sus módulos tengan que llamarlo.
 *
 * Corre después del commit (si el guardado se deshace, no toca el calendario) y nunca rompe la
 * operación de origen: un fallo aquí se reporta y el respaldo diario lo corrige.
 */
class CalendarSourceObserver implements ShouldHandleEventsAfterCommit
{
    /** @var array<int, class-string<Model>> */
    public const MODELS = [
        Tender::class, Opportunity::class, Contract::class, ContractDelivery::class, ContractFollowUp::class, Document::class,
    ];

    /** Registro en `AppServiceProvider::boot`. */
    public static function register(): void
    {
        foreach (self::MODELS as $model) {
            $model::observe(self::class);
        }
    }

    public function __construct(protected CalendarSyncService $calendar) {}

    public function saved(Model $model): void
    {
        $this->guard(fn () => $this->calendar->sync($model));
    }

    public function deleted(Model $model): void
    {
        $this->guard(fn () => $this->calendar->removed($model));
    }

    public function restored(Model $model): void
    {
        $this->guard(fn () => $this->calendar->sync($model));
    }

    protected function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
