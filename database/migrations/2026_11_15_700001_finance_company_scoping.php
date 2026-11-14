<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulos financieros por empresa:
 *
 * - `revolving_budgets` gana `company_id`: había un único presupuesto compartido
 *   por todo el grupo. Cada empresa tiene ahora el suyo (uno por empresa).
 * - Número de factura de cobro y referencia de movimiento pasan de únicos
 *   globales a únicos por empresa.
 * - Índices para los filtros y agregados de reportes, KPI, flujo de caja y POA.
 */
return new class extends Migration
{
    /**
     * tabla => [nombre => columnas]
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indexes = [
        'collection_invoices' => [
            'ix_collection_invoices_company_id_status_paid_at' => ['company_id', 'status', 'paid_at'],
        ],
        'budget_movements' => [
            'ix_budget_movements_company_id_moved_at' => ['company_id', 'moved_at'],
        ],
        'manual_cash_incomes' => [
            'ix_manual_cash_incomes_company_id_received_at' => ['company_id', 'received_at'],
        ],
        'poa_entries' => [
            'ix_poa_entries_company_id_year_status' => ['company_id', 'year', 'status'],
            'ix_poa_entries_company_id_estimated_date' => ['company_id', 'estimated_date'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('revolving_budgets', 'company_id')) {
            Schema::table('revolving_budgets', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }

        $this->assignExistingBudgets();

        Schema::table('revolving_budgets', function (Blueprint $table) {
            $table->unique(['company_id'], 'uq_revolving_budgets_company_id');
        });

        $this->replaceUnique('collection_invoices', ['number'], ['company_id', 'number'], 'uq_collection_invoices_company_id_number');
        $this->replaceUnique('budget_movements', ['reference'], ['company_id', 'reference'], 'uq_budget_movements_company_id_reference');
        $this->replaceUnique('budget_movements', ['source_type', 'source_id'], ['company_id', 'source_type', 'source_id'], 'uq_budget_movements_company_id_source');

        foreach ($this->indexes as $table => $indexes) {
            $existing = array_column(Schema::getIndexes($table), 'name');

            Schema::table($table, function (Blueprint $blueprint) use ($indexes, $existing) {
                foreach ($indexes as $name => $columns) {
                    if (! in_array($name, $existing, true)) {
                        $blueprint->index($columns, $name);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach (array_keys($indexes) as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }

        Schema::table('budget_movements', function (Blueprint $table) {
            $table->dropUnique('uq_budget_movements_company_id_source');
            $table->unique(['source_type', 'source_id']);
            $table->dropUnique('uq_budget_movements_company_id_reference');
            $table->unique(['reference']);
        });

        Schema::table('collection_invoices', function (Blueprint $table) {
            $table->dropUnique('uq_collection_invoices_company_id_number');
            $table->unique(['number']);
        });

        Schema::table('revolving_budgets', function (Blueprint $table) {
            $table->dropUnique('uq_revolving_budgets_company_id');
            $table->dropConstrainedForeignId('company_id');
        });
    }

    /**
     * El presupuesto que ya existía (sin empresa) pasa a la empresa de sus
     * movimientos o, si no tiene, a la primera empresa del grupo.
     */
    private function assignExistingBudgets(): void
    {
        $firstCompany = DB::table('companies')->min('id');

        foreach (DB::table('revolving_budgets')->whereNull('company_id')->orderBy('id')->pluck('id') as $budgetId) {
            $companyId = DB::table('budget_movements')->where('revolving_budget_id', $budgetId)->whereNotNull('company_id')->value('company_id') ?? $firstCompany;
            $taken = $companyId !== null && DB::table('revolving_budgets')->where('company_id', $companyId)->exists();

            DB::table('revolving_budgets')->where('id', $budgetId)->update(['company_id' => $taken ? null : $companyId]);
        }
    }

    /**
     * @param  array<int, string>  $from
     * @param  array<int, string>  $to
     */
    private function replaceUnique(string $table, array $from, array $to, string $name): void
    {
        $existing = null;
        $names = [];

        foreach (Schema::getIndexes($table) as $index) {
            $names[] = $index['name'];

            if ($index['unique'] && ! $index['primary'] && $index['columns'] === $from) {
                $existing = $index['name'];
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($existing, $to, $name, $names) {
            if ($existing !== null) {
                $blueprint->dropUnique($existing);
            }

            if (! in_array($name, $names, true)) {
                $blueprint->unique($to, $name);
            }
        });
    }
};
