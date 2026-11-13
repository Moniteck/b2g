<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Las autorizaciones pueden emitirse desde la interfaz del CRM, sin
        // una conexion MCP asociada.
        Schema::table('mcp_authorizations', function (Blueprint $table) {
            $table->foreignId('mcp_connection_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_authorizations', function (Blueprint $table) {
            $table->foreignId('mcp_connection_id')->nullable(false)->change();
        });
    }
};
