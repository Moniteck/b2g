<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MCP y auditoría
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('client_id')->nullable();
            $table->string('client_name')->nullable();
            $table->json('company_ids')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('authorized_at')->nullable()->comment('Última vez que el usuario aprobó el acceso');
            $table->timestamp('expires_at')->nullable()->comment('Vencimiento de la conexión: exige volver a aprobar');
            $table->string('token_hash')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['revoked_at', 'expires_at'], 'ix_mcp_connections_revoked_expires');
            $table->index('client_id');
            $table->unique('token_hash');
            $table->unique(['user_id', 'client_id'], 'uq_mcp_connections_user_client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connections');
    }
};
