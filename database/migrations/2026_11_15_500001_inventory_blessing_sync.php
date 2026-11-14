<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario Blessing (Fase 11): identidad por (empresa, id de Blessing),
 * marca de demostración, moneda, corridas de sincronización con contadores e
 * historial de precios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->char('currency', 3)->default('HNL')->after('tax_included');
            $table->boolean('is_demo')->default(false)->after('is_active');
            $table->timestamp('deactivated_at')->nullable()->after('is_demo');

            $table->unique(['company_id', 'blessing_id'], 'uq_inventory_items_company_id_blessing_id');
            $table->index(['company_id', 'is_active', 'category'], 'ix_inventory_items_company_id_is_active_category');
            $table->index(['company_id', 'is_active', 'stock_available'], 'ix_inventory_items_company_id_is_active_stock_available');
        });

        Schema::table('inventory_sync_runs', function (Blueprint $table) {
            $table->string('trigger', 20)->default('manual')->after('status');
            $table->string('source', 20)->nullable()->after('trigger');
            $table->foreignId('user_id')->nullable()->after('company_id')
                ->constrained('users', indexName: 'fk_inventory_sync_runs_users')->nullOnDelete();
            $table->unsignedInteger('created_count')->default(0)->after('items_processed');
            $table->unsignedInteger('updated_count')->default(0)->after('created_count');
            $table->unsignedInteger('deactivated_count')->default(0)->after('updated_count');
            $table->unsignedInteger('error_count')->default(0)->after('deactivated_count');
            $table->json('errors')->nullable()->after('error');

            $table->index(['company_id', 'started_at'], 'ix_inventory_sync_runs_company_id_started_at');
        });

        Schema::create('inventory_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', indexName: 'fk_inventory_price_histories_companies')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items', indexName: 'fk_inventory_price_histories_inventory_items')->cascadeOnDelete();
            $table->decimal('cost', 14, 2)->nullable();
            $table->decimal('suggested_price', 14, 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->boolean('tax_included')->default(false);
            $table->timestamp('recorded_at');

            $table->index(['inventory_item_id', 'recorded_at'], 'ix_inventory_price_histories_inventory_item_id_recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_price_histories');

        Schema::table('inventory_sync_runs', function (Blueprint $table) {
            $table->dropIndex('ix_inventory_sync_runs_company_id_started_at');
            $table->dropForeign('fk_inventory_sync_runs_users');
            $table->dropColumn(['trigger', 'source', 'user_id', 'created_count', 'updated_count', 'deactivated_count', 'error_count', 'errors']);
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropUnique('uq_inventory_items_company_id_blessing_id');
            $table->dropIndex('ix_inventory_items_company_id_is_active_category');
            $table->dropIndex('ix_inventory_items_company_id_is_active_stock_available');
            $table->dropColumn(['currency', 'is_demo', 'deactivated_at']);
        });
    }
};
