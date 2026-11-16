<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MCP y auditoría
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('channel')->default('web');
            $table->foreignId('mcp_connection_id')->nullable()->constrained('mcp_connections')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('parent_type', 60)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('request_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('action');
            $table->index('channel');
            $table->index('request_id');
            $table->index('resource_type');
            $table->index(['resource_type', 'resource_id']);
            $table->index(['company_id', 'created_at'], 'ix_audit_logs_company_id_created_at');
            $table->index(['company_id', 'parent_type', 'parent_id', 'created_at'], 'ix_audit_logs_company_id_parent_type_parent_id_created_at');
            $table->index(['company_id', 'resource_type'], 'ix_audit_logs_company_id_resource_type');
            $table->index(['company_id', 'user_id', 'created_at'], 'ix_audit_logs_company_id_user_id_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
