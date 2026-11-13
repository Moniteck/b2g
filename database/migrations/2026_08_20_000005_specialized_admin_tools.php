<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id(); $table->string('sku')->unique(); $table->string('description'); $table->string('category')->nullable()->index(); $table->string('brand')->nullable(); $table->string('unit')->default('Unidad'); $table->decimal('stock', 14, 2)->default(0); $table->decimal('cost', 14, 2)->default(0); $table->decimal('price', 14, 2)->default(0); $table->timestamps();
        });
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('title'); $table->string('institution')->nullable(); $table->string('lot')->nullable(); $table->string('status')->default('Borrador')->index(); $table->json('source')->nullable(); $table->json('lines'); $table->decimal('total', 14, 2)->default(0); $table->timestamps();
        });
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('type'); $table->string('title'); $table->string('recipient'); $table->longText('content'); $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::table('events', function (Blueprint $table) {
            $table->string('priority')->default('Normal'); $table->string('reference_type')->nullable(); $table->unsignedBigInteger('reference_id')->nullable(); $table->boolean('completed')->default(false); $table->timestamp('completed_at')->nullable(); $table->index(['starts_at', 'type', 'priority']);
        });
    }
    public function down(): void { Schema::table('events', fn (Blueprint $table) => $table->dropIndex(['starts_at', 'type', 'priority'])); Schema::dropIfExists('generated_documents'); Schema::dropIfExists('quote_requests'); Schema::dropIfExists('inventory_items'); }
};
