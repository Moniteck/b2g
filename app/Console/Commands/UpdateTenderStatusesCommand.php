<?php

namespace App\Console\Commands;

use App\Services\Tenders\TenderLifecycle;
use Illuminate\Console\Command;

/**
 * Estados automáticos de las licitaciones (B-19): las abiertas cuya recepción de ofertas pasó sin
 * oferta presentada pasan a «Vencida». Programado a diario en `routes/console.php`; la lista y la
 * ficha ya lo calculan al vuelo, así que el comando solo deja el estado guardado al día.
 *
 *   php artisan licitaciones:actualizar-estados [--company=3]
 */
class UpdateTenderStatusesCommand extends Command
{
    protected $signature = 'licitaciones:actualizar-estados {--company= : Solo esta empresa}';

    protected $description = 'Marca como «Vencida» las licitaciones abiertas cuya recepción de ofertas pasó sin oferta.';

    public function handle(TenderLifecycle $lifecycle): int
    {
        $company = $this->option('company') !== null ? (int) $this->option('company') : null;
        $count = $lifecycle->expireOverdue($company);

        $this->info("Licitaciones marcadas como vencidas: {$count}.");

        return self::SUCCESS;
    }
}
