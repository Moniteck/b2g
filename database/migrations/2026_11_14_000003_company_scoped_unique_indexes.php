<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los identificadores de negocio pasan de únicos globales a únicos POR EMPRESA:
 * dos empresas del grupo pueden tener la cotización COT-2026-0001, participar
 * en el mismo expediente o vender al mismo RTN.
 *
 * MySQL no tiene índices parciales: el único incluye los registros con borrado
 * lógico, de modo que un número eliminado no se reutiliza (comportamiento deseado).
 */
return new class extends Migration
{
    /**
     * tabla => [columnas del único anterior, nombre del único nuevo]
     *
     * @var array<string, array{0: array<int, string>, 1: string}>
     */
    private array $uniques = [
        'quotes' => [['number'], 'uq_quotes_company_id_number'],
        'contracts' => [['number'], 'uq_contracts_company_id_number'],
        'inventory_items' => [['sku'], 'uq_inventory_items_company_id_sku'],
        'clients' => [['tax_id'], 'uq_clients_company_id_tax_id'],
        'tenders' => [['reference'], 'uq_tenders_company_id_reference'],
        'kpi_goals' => [['metric', 'period_start', 'period_end'], 'uq_kpi_goals_company_id_metric_period'],
    ];

    public function up(): void
    {
        foreach ($this->uniques as $table => [$columns, $name]) {
            $existing = $this->uniqueIndexName($table, $columns);

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $existing) {
                if ($existing !== null) {
                    $blueprint->dropUnique($existing);
                }

                $blueprint->unique(['company_id', ...$columns], $name);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->uniques as $table => [$columns, $name]) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->dropUnique($name);
                $blueprint->unique($columns);
            });
        }
    }

    /**
     * Nombre del índice único que cubre exactamente esas columnas, si existe.
     *
     * @param  array<int, string>  $columns
     */
    private function uniqueIndexName(string $table, array $columns): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['unique'] && ! $index['primary'] && $index['columns'] === $columns) {
                return $index['name'];
            }
        }

        return null;
    }
};
