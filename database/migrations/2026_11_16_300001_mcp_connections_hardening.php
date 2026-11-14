<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 12 (MCP): conexiones con vigencia y revocación, códigos de confirmación de un
 * solo uso y trazabilidad de la conexión en la auditoría.
 *
 * - mcp_connections: una por usuario y cliente OAuth (`uq_mcp_connections_user_client`),
 *   con fecha de autorización, vencimiento y quién la revocó.
 * - mcp_authorizations: el código de confirmación se guarda como hash (nunca en claro) junto
 *   al resumen que vio el usuario.
 * - audit_logs: conexión MCP que originó la acción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_connections', function (Blueprint $table) {
            $table->timestamp('authorized_at')->nullable()->after('scopes')->comment('Última vez que el usuario aprobó el acceso');
            $table->timestamp('expires_at')->nullable()->after('authorized_at')->comment('Vencimiento de la conexión: exige volver a aprobar');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->unique(['user_id', 'client_id'], 'uq_mcp_connections_user_client');
            $table->index(['revoked_at', 'expires_at'], 'ix_mcp_connections_revoked_expires');
        });

        Schema::table('mcp_authorizations', function (Blueprint $table) {
            $table->string('code_hash', 64)->nullable()->after('payload_hash')->comment('SHA-256 del código de confirmación de un solo uso');
            $table->json('summary')->nullable()->after('code_hash')->comment('Resumen de la acción que se mostró antes de confirmar');
            $table->unique('code_hash', 'uq_mcp_authorizations_code_hash');
            $table->index(['mcp_connection_id', 'action'], 'ix_mcp_authorizations_connection_action');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('mcp_connection_id')->nullable()->after('channel')->constrained('mcp_connections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mcp_connection_id');
        });

        Schema::table('mcp_authorizations', function (Blueprint $table) {
            $table->dropUnique('uq_mcp_authorizations_code_hash');
            $table->dropIndex('ix_mcp_authorizations_connection_action');
            $table->dropColumn(['code_hash', 'summary']);
        });

        Schema::table('mcp_connections', function (Blueprint $table) {
            $table->dropUnique('uq_mcp_connections_user_client');
            $table->dropIndex('ix_mcp_connections_revoked_expires');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['authorized_at', 'expires_at']);
        });
    }
};
