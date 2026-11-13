<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('blessing_id')->nullable()->index()->after('sku');
            $table->decimal('suggested_price', 14, 2)->nullable()->after('price');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('suggested_price');
            $table->boolean('tax_included')->default(true)->after('tax_rate');
            $table->decimal('stock_available', 14, 2)->nullable()->after('stock');
            $table->timestamp('synced_at')->nullable()->index()->after('stock_available');
            $table->json('raw')->nullable()->after('synced_at');
            $table->boolean('is_active')->default(true)->index()->after('raw');
        });

        Schema::create('inventory_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('Pendiente')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('items_processed')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_sync_runs');

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn([
                'blessing_id', 'suggested_price', 'tax_rate', 'tax_included',
                'stock_available', 'synced_at', 'raw', 'is_active',
            ]);
        });
    }
};
