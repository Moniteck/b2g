<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Comercial: pipeline, cotizaciones y clientes
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->nullable()->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->cascadeOnDelete();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->decimal('amount', 14, 2)->default('0.00');
            $table->string('currency', 3)->default('HNL');
            $table->boolean('includes_tax')->default(true);
            $table->boolean('is_ours')->default(false);
            $table->boolean('is_winner')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['opportunity_id', 'position'], 'ix_quote_participants_opportunity_id_position');
            $table->index(['quote_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_participants');
    }
};
