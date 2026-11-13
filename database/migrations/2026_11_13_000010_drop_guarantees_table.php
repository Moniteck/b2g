<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina el módulo de Garantías Bancarias, retirado del alcance del CRM.
 * El down() reconstruye la tabla con la estructura acumulada de las migraciones
 * 2026_08_20_000002 y 2026_08_20_000004 (sin datos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('guarantees');
    }

    public function down(): void
    {
        if (Schema::hasTable('guarantees')) {
            return;
        }

        Schema::create('guarantees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('issuer')->nullable();
            $table->string('number')->nullable()->unique();
            $table->decimal('amount', 14, 2);
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->index();
            $table->string('status')->default('Vigente')->index();
            $table->text('notes')->nullable();
            $table->decimal('percentage', 5, 2)->default(0);
            $table->string('currency', 3)->default('HNL');
            $table->date('released_at')->nullable();
            $table->date('executed_at')->nullable();
            $table->timestamps();
        });
    }
};
