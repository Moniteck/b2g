<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El historial de procesos pasa a ser por empresa, como el resto de los
 * expedientes. Nulable porque los registros previos se asignan en la migración
 * de datos 2026_11_14_000006.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('process_histories', 'company_id')) {
            return;
        }

        Schema::table('process_histories', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')
                ->comment('Empresa del grupo dueña del registro')
                ->constrained('companies', indexName: 'fk_process_histories_companies')
                ->nullOnDelete();
            $table->index(['company_id', 'closed_at'], 'ix_process_histories_company_id_closed_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('process_histories', 'company_id')) {
            return;
        }

        Schema::table('process_histories', function (Blueprint $table) {
            $table->dropForeign('fk_process_histories_companies');
            $table->dropIndex('ix_process_histories_company_id_closed_at');
            $table->dropColumn('company_id');
        });
    }
};
