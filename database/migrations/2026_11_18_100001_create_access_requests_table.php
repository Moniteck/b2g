<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de acceso enviadas desde la pantalla de acceso («Crear cuenta»).
 *
 * Viven en su propia tabla y no en `users`: mientras no se aprueban no existe cuenta, así que una
 * solicitud pendiente nunca puede iniciar sesión ni aparecer como miembro de una empresa. Al aprobar
 * se crea el usuario con la contraseña (ya cifrada) que la persona eligió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->index();
            $table->string('phone', 40)->nullable();
            $table->string('position', 120);
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_name', 160)->nullable();
            $table->string('password');
            $table->string('status', 20)->default('pending');
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('approved_role', 20)->nullable();
            $table->timestamps();
            $table->index(['status', 'company_id'], 'access_requests_status_company_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
