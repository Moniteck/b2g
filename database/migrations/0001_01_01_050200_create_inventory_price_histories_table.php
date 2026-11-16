<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Inventario (Blessing)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
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
    }
};
