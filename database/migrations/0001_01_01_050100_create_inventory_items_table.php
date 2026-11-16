<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Inventario (Blessing)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('sku');
            $table->string('blessing_id')->nullable();
            $table->string('description');
            $table->string('category')->nullable();
            $table->string('brand')->nullable();
            $table->string('unit')->default('Unidad');
            $table->decimal('stock', 14, 2)->default('0.00');
            $table->decimal('stock_available', 14, 2)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('raw')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->timestamp('deactivated_at')->nullable();
            $table->decimal('cost', 14, 2)->default('0.00');
            $table->decimal('suggested_price', 14, 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->boolean('tax_included')->default(true);
            $table->char('currency', 3)->default('HNL');
            $table->timestamps();
            $table->index('blessing_id');
            $table->index('category');
            $table->index('is_active');
            $table->index('synced_at');
            $table->index(['company_id', 'is_active'], 'ix_inventory_items_company_id_is_active');
            $table->index(['company_id', 'is_active', 'category'], 'ix_inventory_items_company_id_is_active_category');
            $table->index(['company_id', 'is_active', 'stock_available'], 'ix_inventory_items_company_id_is_active_stock_available');
            $table->unique(['company_id', 'blessing_id'], 'uq_inventory_items_company_id_blessing_id');
            $table->unique(['company_id', 'sku'], 'uq_inventory_items_company_id_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
