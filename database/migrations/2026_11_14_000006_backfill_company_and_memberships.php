<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migración de datos (idempotente) de la base de demostración previa al modelo
 * multiempresa:
 *
 *  1. Todo registro con `company_id` nulo pasa a «CAP Honduras».
 *  2. Los usuarios que no tienen ninguna membresía quedan como administradores
 *     de CAP Honduras.
 *  3. `root@crm-b2g.test` queda como raíz del grupo.
 *
 * En una base sin la empresa «CAP Honduras» (instalación nueva, pruebas) no hace nada.
 */
return new class extends Migration
{
    /** Tablas cuyo `company_id` es la empresa dueña del registro. */
    private array $tables = [
        'budget_movements', 'clients', 'collection_invoices', 'compliance_matrices', 'consortia',
        'contracts', 'documents', 'events', 'institutional_relations', 'inventory_items',
        'inventory_sync_runs', 'kpi_goals', 'manual_cash_incomes', 'notification_templates',
        'opportunities', 'poa_entries', 'process_histories', 'quotes', 'reminder_deliveries',
        'signature_applications', 'tenders',
    ];

    public function up(): void
    {
        $companyId = DB::table('companies')->where('name', 'CAP Honduras')->value('id');

        if ($companyId === null) {
            return;
        }

        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                DB::table($table)->whereNull('company_id')->update(['company_id' => $companyId]);
            }
        }

        $withoutMembership = DB::table('users')
            ->whereNotIn('id', DB::table('company_user')->select('user_id'))
            ->pluck('id');

        foreach ($withoutMembership as $userId) {
            DB::table('company_user')->insert([
                'company_id' => $companyId,
                'user_id' => $userId,
                'role' => 'admin',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('users')->where('email', 'root@crm-b2g.test')->update(['is_root' => true]);
    }

    public function down(): void
    {
        // Migración de datos: no se revierte.
    }
};
