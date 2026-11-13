<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La migración 2026_11_13_000002 creaba `origin`, `dedupe_key` y
 * `contact_user_id` solo si `source_quote_id` no existía, así que en las bases
 * donde esa columna ya estaba (la local) nunca se crearon y el modelo `Client`
 * las da por hechas. Aquí se igualan todas las bases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'origin')) {
                $table->string('origin')->default('Adjudicacion')->index('ix_clients_origin')
                    ->comment('Cómo nació el cliente: Adjudicacion, Importacion...');
            }

            if (! Schema::hasColumn('clients', 'dedupe_key')) {
                $table->string('dedupe_key')->nullable()->unique('uq_clients_dedupe_key')
                    ->comment('empresa|institución|nombre normalizado; evita duplicar clientes al adjudicar');
            }

            if (! Schema::hasColumn('clients', 'contact_user_id')) {
                $table->string('contact_user_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Las columnas pertenecen a 2026_11_13_000002, que las elimina en su down().
    }
};
