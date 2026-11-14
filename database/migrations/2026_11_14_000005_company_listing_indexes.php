<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para los listados: toda consulta filtra primero por la empresa
 * activa y después por estado o fecha.
 */
return new class extends Migration
{
    /**
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indexes = [
        'quotes' => [
            'ix_quotes_company_id_status' => ['company_id', 'status'],
            'ix_quotes_company_id_outcome' => ['company_id', 'outcome'],
            'ix_quotes_company_id_quoted_at' => ['company_id', 'quoted_at'],
        ],
        'opportunities' => [
            'ix_opportunities_company_id_stage' => ['company_id', 'stage'],
        ],
        'tenders' => [
            'ix_tenders_company_id_status' => ['company_id', 'status'],
            'ix_tenders_company_id_presentation_deadline' => ['company_id', 'presentation_deadline'],
            'ix_tenders_company_id_closing_date' => ['company_id', 'closing_date'],
        ],
        'documents' => [
            'ix_documents_company_id_expires_at' => ['company_id', 'expires_at'],
            'ix_documents_company_id_kind_category' => ['company_id', 'kind', 'category'],
        ],
        'clients' => [
            'ix_clients_company_id_status' => ['company_id', 'status'],
        ],
        'collection_invoices' => [
            'ix_collection_invoices_company_id_status_due_at' => ['company_id', 'status', 'due_at'],
        ],
        'reminder_deliveries' => [
            'ix_reminder_deliveries_company_id_status_scheduled_for' => ['company_id', 'status', 'scheduled_for'],
        ],
        'audit_logs' => [
            'ix_audit_logs_company_id_created_at' => ['company_id', 'created_at'],
        ],
        'inventory_items' => [
            'ix_inventory_items_company_id_is_active' => ['company_id', 'is_active'],
        ],
    ];

    public function up(): void
    {
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
            $existing = array_column(Schema::getIndexes($table), 'name');

            Schema::table($table, function (Blueprint $blueprint) use ($indexes, $existing) {
                foreach (array_keys($indexes) as $name) {
                    if (in_array($name, $existing, true)) {
                        $blueprint->dropIndex($name);
                    }
                }
            });
        }
    }
};
