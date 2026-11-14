<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La campana también recibe avisos que no salen de un plazo del calendario (p. ej. «Nueva
 * solicitud de acceso»): `event_id` pasa a ser opcional y el aviso lleva su propio título,
 * detalle y enlace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminder_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->nullable()->change();
            $table->string('title')->nullable()->after('offset_key');
            $table->string('body', 500)->nullable()->after('title');
            $table->string('href')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('reminder_deliveries', function (Blueprint $table) {
            $table->dropColumn(['title', 'body', 'href']);
        });
    }
};
