<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MCP y auditoría
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcp_connection_id')->nullable()->constrained('mcp_connections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('action');
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('payload_hash')->nullable();
            $table->string('code_hash', 64)->nullable()->comment('SHA-256 del código de confirmación de un solo uso');
            $table->json('summary')->nullable()->comment('Resumen de la acción que se mostró antes de confirmar');
            $table->timestamp('authorized_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['mcp_connection_id', 'action'], 'ix_mcp_authorizations_connection_action');
            $table->index('action');
            $table->unique('code_hash', 'uq_mcp_authorizations_code_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_authorizations');
    }
};
