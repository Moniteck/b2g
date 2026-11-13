<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base de clientes, catálogos y administración.
 *
 * - `contracts.client_id`: el contrato apunta a su cliente (antes la relación
 *   `Client::contracts()` usaba una columna inexistente). Se rellena desde la
 *   cotización de origen (`quotes.client_id`).
 * - `companies`: el token de Blessing pasa a `text` cifrado, y se añaden la tasa de
 *   ISV y si los precios incluyen impuesto.
 * - Índices para los filtros y la paginación de clientes, instituciones, contactos
 *   y auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'client_id')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->after('company_id')
                    ->constrained('clients')->nullOnDelete();
            });
        }

        DB::table('contracts')->whereNull('client_id')->whereNotNull('quote_id')->orderBy('id')
            ->each(function (object $contract) {
                $clientId = DB::table('quotes')->where('id', $contract->quote_id)->value('client_id');
                if ($clientId !== null) {
                    DB::table('contracts')->where('id', $contract->id)->update(['client_id' => $clientId]);
                }
            });

        Schema::table('companies', function (Blueprint $table) {
            $table->text('blessing_token')->nullable()->change();

            if (! Schema::hasColumn('companies', 'tax_rate')) {
                $table->decimal('tax_rate', 5, 2)->default(15)->after('blessing_price_list');
            }
            if (! Schema::hasColumn('companies', 'prices_include_tax')) {
                $table->boolean('prices_include_tax')->default(false)->after('tax_rate');
            }
        });

        // Cifra los tokens que se hubieran guardado en claro.
        DB::table('companies')->whereNotNull('blessing_token')->orderBy('id')->each(function (object $company) {
            try {
                Crypt::decryptString($company->blessing_token);
            } catch (Throwable) {
                DB::table('companies')->where('id', $company->id)
                    ->update(['blessing_token' => Crypt::encryptString($company->blessing_token)]);
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->index(['company_id', 'last_purchase_at'], 'ix_clients_company_id_last_purchase_at');
            $table->index(['company_id', 'name'], 'ix_clients_company_id_name');
        });

        Schema::table('institutions', function (Blueprint $table) {
            $table->index('type', 'ix_institutions_type');
            $table->index('potential', 'ix_institutions_potential');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['institution_id', 'name'], 'ix_contacts_institution_id_name');
            $table->index('name', 'ix_contacts_name');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['company_id', 'user_id', 'created_at'], 'ix_audit_logs_company_id_user_id_created_at');
            $table->index(['company_id', 'resource_type'], 'ix_audit_logs_company_id_resource_type');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('ix_audit_logs_company_id_user_id_created_at');
            $table->dropIndex('ix_audit_logs_company_id_resource_type');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('ix_contacts_institution_id_name');
            $table->dropIndex('ix_contacts_name');
        });

        Schema::table('institutions', function (Blueprint $table) {
            $table->dropIndex('ix_institutions_type');
            $table->dropIndex('ix_institutions_potential');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('ix_clients_company_id_last_purchase_at');
            $table->dropIndex('ix_clients_company_id_name');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'prices_include_tax']);
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
