<?php

namespace App\Services\Contracts;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Plazo del contrato: fecha fin a partir del inicio y de la duración.
 *
 * Regla: el plazo de N días corre desde el día siguiente al inicio (el hito).
 * - Calendario: fin = inicio + N días.
 * - Hábiles: se cuentan N días de lunes a viernes que no sean feriado de la empresa.
 */
class ContractTerm
{
    /** @var array<int, array<string, true>> feriados por empresa, en memoria durante la petición */
    private array $cache = [];

    public function endDate(mixed $start, ?int $days, bool $businessDays, ?int $companyId): ?string
    {
        if ($start === null || $start === '' || $days === null || $days < 1) {
            return null;
        }

        $cursor = CarbonImmutable::parse($start)->startOfDay();

        if (! $businessDays) {
            return $cursor->addDays($days)->toDateString();
        }

        $holidays = $this->holidays($companyId);
        $added = 0;

        while ($added < $days) {
            $cursor = $cursor->addDay();
            if ($cursor->isWeekday() && ! isset($holidays[$cursor->toDateString()])) {
                $added++;
            }
        }

        return $cursor->toDateString();
    }

    /**
     * Siembra los feriados nacionales si la empresa nunca tuvo ninguno (ni borrados).
     */
    public function ensureSeeded(?int $companyId): void
    {
        if ($companyId === null || Holiday::query()->withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            return;
        }

        DB::transaction(function () use ($companyId) {
            $now = now();
            Holiday::query()->insert(array_map(fn (array $row) => $row + [
                'company_id' => $companyId, 'created_at' => $now, 'updated_at' => $now,
            ], HondurasHolidays::defaults()));
        });

        unset($this->cache[$companyId]);
    }

    /** @return array<string, true> */
    private function holidays(?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }

        if (! isset($this->cache[$companyId])) {
            $this->ensureSeeded($companyId);
            $this->cache[$companyId] = Holiday::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->pluck('holiday_date')
                ->mapWithKeys(fn ($date) => [CarbonImmutable::parse($date)->toDateString() => true])
                ->all();
        }

        return $this->cache[$companyId];
    }

    public function forget(?int $companyId = null): void
    {
        if ($companyId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$companyId]);
        }
    }
}
