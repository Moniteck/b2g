<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Models\InventorySyncRun;
use App\Services\Blessing\BlessingClientFactory;
use App\Services\Blessing\FakeBlessingClient;
use App\Services\Blessing\InventorySyncService;
use Illuminate\Console\Command;

/**
 * Siembra el inventario de DEMOSTRACIÓN (catálogo simulado de Blessing) en una
 * empresa. Solo con APP_ENV local o testing. Los productos quedan con
 * `is_demo = 1` y la empresa con `settings.blessing.demo = true`, de modo que la
 * sincronización programada siga usando el catálogo simulado en local.
 *
 *   php artisan blessing:demo                 primera empresa activa
 *   php artisan blessing:demo 2               empresa 2 (id, código o nombre)
 *   php artisan blessing:demo 2 --limpiar     quita el inventario de demostración
 */
class BlessingDemoCommand extends Command
{
    protected $signature = 'blessing:demo
                            {empresa? : Id, código o nombre de la empresa}
                            {--limpiar : Elimina el inventario de demostración y apaga el modo demo}';

    protected $description = 'Siembra (o limpia) el inventario de demostración de Blessing. Solo en local y pruebas.';

    public function handle(BlessingClientFactory $factory, InventorySyncService $service): int
    {
        if (! $factory->demoAllowed()) {
            $this->error('El inventario de demostración solo se permite con APP_ENV local o testing.');

            return self::FAILURE;
        }

        $company = SyncBlessingCommand::findCompanies($this->argument('empresa'))?->first();

        if ($company === null) {
            $this->error('No existe la empresa indicada.');

            return self::FAILURE;
        }

        $settings = $company->settings ?? [];

        if ($this->option('limpiar')) {
            $deleted = InventoryItem::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('is_demo', true)->delete();
            $settings['blessing']['demo'] = false;
            $company->forceFill(['settings' => $settings])->save();
            $this->info("{$company->name}: {$deleted} productos de demostración eliminados.");

            return self::SUCCESS;
        }

        if (trim((string) $company->blessing_base_url) !== '' && trim((string) $company->blessing_token) !== '') {
            $this->error("{$company->name} ya tiene la API real de Blessing configurada; no se siembra la demostración.");

            return self::FAILURE;
        }

        $settings['blessing']['demo'] = true;
        $company->forceFill(['settings' => $settings])->save();

        $run = $service->sync($company, InventorySyncRun::TRIGGER_DEMO, null, new FakeBlessingClient);

        $this->info(sprintf(
            '%s: inventario de demostración %s · %d altas, %d cambios, %d bajas.',
            $company->name, mb_strtolower($run->status), $run->created_count, $run->updated_count, $run->deactivated_count,
        ));

        return $run->status === InventorySyncRun::FAILED ? self::FAILURE : self::SUCCESS;
    }
}
