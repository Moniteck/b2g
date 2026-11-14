<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limpieza final: tablas y columnas que ningún módulo usa.
 *
 * - `purchase_orders` y `delivery_certificates`: las entregas y actas del contrato viven en
 *   `contract_deliveries` (módulo de contratos).
 * - `delivery_alarms`: la alarma de entrega antigua de cotizaciones; los avisos van por el calendario.
 * - `documents.parent_id` e `is_current`: las versiones de un documento viven en `document_versions`.
 *
 * `down()` vuelve a crear la estructura (sin datos: estaban vacías).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('delivery_alarms');
        Schema::dropIfExists('delivery_certificates');
        Schema::dropIfExists('purchase_orders');

        if (Schema::hasColumn('documents', 'parent_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropForeign(['parent_id']);
            });
            Schema::table('documents', function (Blueprint $table) {
                $table->dropIndex(['is_current']);
            });
            Schema::table('documents', function (Blueprint $table) {
                $table->dropColumn(['parent_id', 'is_current']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('documents', 'parent_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->foreignId('parent_id')->nullable()->after('version')->constrained('documents')->nullOnDelete();
                $table->boolean('is_current')->default(true)->index()->after('parent_id');
            });
        }

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->decimal('amount', 14, 2);
            $table->date('issued_at');
            $table->string('status')->default('Pendiente');
            $table->timestamps();
            $table->unique(['contract_id', 'number']);
        });

        Schema::create('delivery_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->decimal('amount', 14, 2)->default(0);
            $table->date('issued_at');
            $table->string('status')->default('Pendiente');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['contract_id', 'number']);
        });

        Schema::create('delivery_alarms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('Pendiente')->index();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['quote_id', 'status']);
        });
    }
};
