<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suscripciones Web Push: una fila por usuario y dispositivo (navegador o app instalada).
 *
 * No dependen de la empresa activa: el aviso llega al dispositivo de la persona y el enlace abre la
 * pantalla correcta, donde el servidor vuelve a comprobar permisos. El `endpoint` es largo (URL del
 * servicio push), así que la unicidad va por su huella SHA-256. Las suscripciones que el servicio
 * declara caducadas (404/410) se borran al intentar enviarles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('device_label', 120)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'created_at'], 'push_subscriptions_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
