<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Comercial: pipeline, cotizaciones y clientes
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('description');
            $table->decimal('quantity', 12, 2);
            $table->string('unit')->default('Unidad');
            $table->decimal('unit_cost', 14, 2)->default('0.00');
            $table->decimal('margin', 5, 2)->default('45.00');
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->boolean('tax_included')->default(false);
            $table->string('pricing_source')->default('manual');
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('sku', 100)->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_percent', 5, 2)->default('0.00');
            $table->decimal('subtotal', 14, 2)->default('0.00');
            $table->decimal('tax', 14, 2)->default('0.00');
            $table->decimal('total', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};
