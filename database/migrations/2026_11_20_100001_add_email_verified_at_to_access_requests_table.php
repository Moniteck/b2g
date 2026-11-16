<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seguridad (M4): una solicitud de acceso solo llega a los revisores cuando la persona confirma
 * que controla el correo (enlace firmado de 24 horas). Sin confirmar, caduca y otra solicitud con
 * el mismo correo la reemplaza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_requests', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('ip_address');
        });

        // Las solicitudes anteriores a la verificación ya estaban a la vista de los revisores (y las
        // aprobadas ya tienen cuenta): se conservan como estaban para no dejar a nadie fuera.
        DB::table('access_requests')->whereNull('email_verified_at')->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('access_requests', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
