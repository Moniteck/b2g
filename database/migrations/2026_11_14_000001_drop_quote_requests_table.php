<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina el módulo de Solicitud de Cotización, retirado del alcance del CRM.
 * El down() reconstruye la tabla como la dejó 2026_08_20_000005 (sin datos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('quote_requests');
    }

    public function down(): void
    {
        if (Schema::hasTable('quote_requests')) {
            return;
        }

        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('institution')->nullable();
            $table->string('lot')->nullable();
            $table->string('status')->default('Borrador')->index();
            $table->json('source')->nullable();
            $table->json('lines');
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();
        });
    }
};
