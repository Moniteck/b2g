<?php

namespace App\Console\Commands;

use App\Jobs\SyncBlessingInventory;
use App\Models\Company;
use App\Models\InventorySyncRun;
use App\Services\Blessing\BlessingClientFactory;
use App\Services\Blessing\BlessingException;
use App\Services\Blessing\InventorySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Sincroniza el inventario con Blessing.
 *
 *   php artisan blessing:sync              todas las empresas con conexión
 *   php artisan blessing:sync 3            solo la empresa 3 (id, código o nombre)
 *   php artisan blessing:sync --cola       encola un trabajo por empresa (lo usa la tarea programada)
 */
class SyncBlessingCommand extends Command
{
    protected $signature = 'blessing:sync
                            {empresa? : Id, código o nombre de la empresa}
                            {--cola : Encola la sincronización en lugar de ejecutarla aquí}';

    protected $description = 'Sincroniza el inventario de Blessing de una o de todas las empresas con conexión.';

    public function handle(BlessingClientFactory $factory, InventorySyncService $service): int
    {
        $companies = self::findCompanies($this->argument('empresa'));

        if ($companies === null) {
            $this->error('No existe la empresa indicada.');

            return self::FAILURE;
        }

        $companies = $companies->filter(fn (Company $company) => $factory->forCompany($company)->isConfigured());

        if ($companies->isEmpty()) {
            $this->warn('Ninguna empresa tiene la conexión con Blessing configurada.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($companies as $company) {
            if ($this->option('cola')) {
                SyncBlessingInventory::dispatch($company->id, InventorySyncRun::TRIGGER_SCHEDULED);
                $this->line("Encolada: {$company->name}");

                continue;
            }

            try {
                $run = $service->sync($company, InventorySyncRun::TRIGGER_COMMAND);
            } catch (BlessingException $error) {
                $this->warn("{$company->name}: {$error->getMessage()}");

                continue;
            }

            $failed = $failed || $run->status === InventorySyncRun::FAILED;
            $this->line(sprintf(
                '%s: %s · %d altas, %d cambios, %d bajas, %d errores%s',
                $company->name, $run->status, $run->created_count, $run->updated_count,
                $run->deactivated_count, $run->error_count, $run->error ? " · {$run->error}" : '',
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Empresas por id, código o nombre; todas las activas si no se indica ninguna.
     *
     * @return Collection<int, Company>|null
     */
    public static function findCompanies(?string $needle): ?Collection
    {
        if ($needle === null || $needle === '') {
            return Company::query()->where('is_active', true)->orderBy('id')->get();
        }

        $company = Company::query()
            ->where(fn ($query) => $query->where('code', $needle)->orWhere('name', $needle)
                ->when(ctype_digit($needle), fn ($q) => $q->orWhere('id', (int) $needle)))
            ->first();

        return $company ? collect([$company]) : null;
    }
}
