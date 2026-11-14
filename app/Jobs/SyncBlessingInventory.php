<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\InventorySyncRun;
use App\Models\User;
use App\Services\Blessing\BlessingException;
use App\Services\Blessing\InventorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza el inventario de Blessing de una empresa en segundo plano.
 * Único por empresa: si ya hay uno en la cola, no se encola otro.
 */
class SyncBlessingInventory implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(
        public int $companyId,
        public string $trigger = InventorySyncRun::TRIGGER_SCHEDULED,
        public ?int $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(InventorySyncService $service): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null || ! $company->is_active) {
            return;
        }

        try {
            $service->sync($company, $this->trigger, $this->userId ? User::query()->find($this->userId) : null);
        } catch (BlessingException $error) {
            // Sin configuración o con otra corrida en curso: no es un fallo del trabajo.
            Log::info('Sincronización de Blessing omitida.', ['company_id' => $this->companyId, 'motivo' => $error->getMessage()]);
        }
    }
}
