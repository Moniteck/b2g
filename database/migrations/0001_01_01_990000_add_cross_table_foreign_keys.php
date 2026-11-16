<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Llaves entre tablas que se referencian entre sí (cotizaciones ↔ oportunidades ↔ clientes).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', fn (Blueprint $table) => $table->foreign('quote_id')->references('id')->on('quotes')->nullOnDelete());
        Schema::table('quotes', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('opportunities', fn (Blueprint $table) => $table->dropForeign(['quote_id']));
        Schema::table('quotes', fn (Blueprint $table) => $table->dropForeign(['client_id']));
    }
};
