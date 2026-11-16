<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rendimiento del historial y de los listados por empresa.
 *
 *  - `audit_logs.parent_type` / `parent_id`: registro «padre» de una acción sobre un adjunto
 *    (p. ej. «documento.subido» de una oportunidad). Antes el historial filtraba con un `OR`
 *    sobre el JSON `changes->adjunto_a`, sin índice posible y con filesort. Se rellenan los
 *    registros existentes a partir de ese mismo JSON («Opportunity #7»).
 *  - `ix_opportunities_company_id_created_at` para los reportes y el Dashboard por período.
 *    Los demás índices pedidos por la auditoría ya existían (`ix_quotes_company_id_quoted_at`,
 *    `ix_collection_invoices_company_id_status_paid_at`, `ix_tenders_company_id_closing_date`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('audit_logs', 'parent_type')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->string('parent_type', 60)->nullable()->after('resource_id');
                $table->unsignedBigInteger('parent_id')->nullable()->after('parent_type');
                $table->index(['company_id', 'parent_type', 'parent_id', 'created_at'], 'ix_audit_logs_company_id_parent_type_parent_id_created_at');
            });
        }

        $this->backfillParents();

        if (! Schema::hasIndex('opportunities', 'ix_opportunities_company_id_created_at')) {
            Schema::table('opportunities', function (Blueprint $table) {
                $table->index(['company_id', 'created_at'], 'ix_opportunities_company_id_created_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('opportunities', 'ix_opportunities_company_id_created_at')) {
            Schema::table('opportunities', fn (Blueprint $table) => $table->dropIndex('ix_opportunities_company_id_created_at'));
        }

        if (Schema::hasColumn('audit_logs', 'parent_type')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropIndex('ix_audit_logs_company_id_parent_type_parent_id_created_at');
                $table->dropColumn(['parent_type', 'parent_id']);
            });
        }
    }

    /** Rellena el padre de los registros de adjuntos ya existentes (por lotes, portable). */
    private function backfillParents(): void
    {
        DB::table('audit_logs')
            ->whereNull('parent_type')
            ->where('changes', 'like', '%adjunto_a%')
            ->select(['id', 'changes'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $changes = json_decode((string) $row->changes, true);
                    $target = is_array($changes) ? ($changes['adjunto_a'] ?? null) : null;

                    if (is_string($target) && preg_match('/^([A-Za-z]+) #(\d+)$/', $target, $match)) {
                        DB::table('audit_logs')->where('id', $row->id)->update(['parent_type' => $match[1], 'parent_id' => (int) $match[2]]);
                    }
                }
            });
    }
};
