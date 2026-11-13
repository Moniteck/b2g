<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('quotes', 'tax_included')) {
            Schema::table('quotes', function (Blueprint $table) {
                // Los precios de Blessing ya incluyen impuesto: el total coincide con el subtotal.
                $table->boolean('tax_included')->default(true)->after('discount');
                $table->string('pricing_source')->default('manual')->after('tax_included');
            });
        }

        if (! Schema::hasColumn('quote_items', 'tax_rate')) {
            Schema::table('quote_items', function (Blueprint $table) {
                $table->decimal('tax_rate', 5, 2)->nullable()->after('margin');
                $table->string('pricing_source')->default('manual')->after('tax_rate');
                $table->string('inventory_item_id')->nullable()->after('pricing_source');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quote_items', 'tax_rate')) {
            Schema::table('quote_items', function (Blueprint $table) {
                $table->dropColumn(['tax_rate', 'pricing_source', 'inventory_item_id']);
            });
        }

        if (Schema::hasColumn('quotes', 'tax_included')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->dropColumn(['tax_included', 'pricing_source']);
            });
        }
    }
};
