<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Post-adjudicación (Fase 10).
 *
 * - Estados del contrato con tildes («En ejecución», «Producto entregado») y de la entrega
 *   en femenino («Entregada»): migración de los datos guardados sin tilde.
 * - `starts_at`/`ends_at` (duplicadas) se copian a `start_date`/`end_date` y se retiran.
 *   La base es local y de demostración (decisión 12), por eso se retiran en el mismo despliegue.
 * - `is_end_date_manual`: la fecha fin puesta a mano no se recalcula desde el plazo.
 * - Auditoría `created_by`/`updated_by` en contratos y entregas.
 * - Índices de los filtros de la lista (fin, oficial, institución) y de las entregas por estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('is_end_date_manual')->default(false)->after('end_date')
                ->comment('La fecha fin la fijó una persona: no se recalcula desde el plazo');
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users', 'id', 'fk_contracts_created_by_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users', 'id', 'fk_contracts_updated_by_users')->nullOnDelete();
            $table->index(['company_id', 'end_date'], 'ix_contracts_company_id_end_date');
            $table->index(['company_id', 'manager_id'], 'ix_contracts_company_id_manager_id');
            $table->index(['company_id', 'institution_id'], 'ix_contracts_company_id_institution_id');
        });

        DB::table('contracts')->whereNull('start_date')->whereNotNull('starts_at')->update(['start_date' => DB::raw('starts_at')]);
        DB::table('contracts')->whereNull('end_date')->whereNotNull('ends_at')->update(['end_date' => DB::raw('ends_at')]);

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
        });

        foreach (['En ejecucion' => 'En ejecución', 'Entregado' => 'Producto entregado', 'En Ejecución' => 'En ejecución'] as $from => $to) {
            DB::table('contracts')->where('status', $from)->update(['status' => $to]);
        }
        DB::table('contracts')->where('status', 'Producto entregado')->update(['product_delivered' => true]);

        foreach (['Entregado' => 'Entregada', 'Recibida' => 'Entregada', 'Cancelado' => 'Cancelada', 'Rechazado' => 'Rechazada'] as $from => $to) {
            DB::table('contract_deliveries')->where('status', $from)->update(['status' => $to]);
        }

        Schema::table('contract_deliveries', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users', 'id', 'fk_contract_deliveries_created_by_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users', 'id', 'fk_contract_deliveries_updated_by_users')->nullOnDelete();
            $table->index(['contract_id', 'status'], 'ix_contract_deliveries_contract_id_status');
        });

        Schema::table('contract_follow_ups', function (Blueprint $table) {
            $table->index(['contract_id', 'occurred_at'], 'ix_contract_follow_ups_contract_id_occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('contract_follow_ups', function (Blueprint $table) {
            $table->dropIndex('ix_contract_follow_ups_contract_id_occurred_at');
        });

        Schema::table('contract_deliveries', function (Blueprint $table) {
            $table->dropIndex('ix_contract_deliveries_contract_id_status');
            $table->dropForeign('fk_contract_deliveries_created_by_users');
            $table->dropForeign('fk_contract_deliveries_updated_by_users');
            $table->dropColumn(['created_by', 'updated_by']);
        });

        DB::table('contract_deliveries')->where('status', 'Entregada')->update(['status' => 'Entregado']);
        DB::table('contracts')->where('status', 'En ejecución')->update(['status' => 'En ejecucion']);
        DB::table('contracts')->where('status', 'Producto entregado')->update(['status' => 'Entregado']);

        Schema::table('contracts', function (Blueprint $table) {
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
        });

        DB::table('contracts')->update(['starts_at' => DB::raw('start_date'), 'ends_at' => DB::raw('end_date')]);

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('ix_contracts_company_id_end_date');
            $table->dropIndex('ix_contracts_company_id_manager_id');
            $table->dropIndex('ix_contracts_company_id_institution_id');
            $table->dropForeign('fk_contracts_created_by_users');
            $table->dropForeign('fk_contracts_updated_by_users');
            $table->dropColumn(['is_end_date_manual', 'created_by', 'updated_by']);
        });
    }
};
