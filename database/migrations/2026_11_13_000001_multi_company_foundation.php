<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tablas cuyo contenido pertenece a una empresa del grupo. */
    private const COMPANY_SCOPED = [
        'quotes', 'opportunities', 'tenders', 'clients', 'documents', 'contracts',
        'events', 'collection_invoices', 'institutional_relations', 'poa_entries',
        'consortia', 'inventory_items', 'compliance_matrices', 'budget_movements',
        'manual_cash_incomes', 'kpi_goals', 'notification_templates',
    ];

    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('name');
            $table->string('contact_name')->nullable()->after('representative');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->string('contact_phone')->nullable()->after('contact_email');
            $table->string('logo_path')->nullable()->after('contact_phone');
            $table->boolean('is_active')->default(true)->index()->after('logo_path');
            $table->json('settings')->nullable()->after('is_active');
            $table->string('blessing_base_url')->nullable()->after('settings');
            $table->string('blessing_token')->nullable()->after('blessing_base_url');
            $table->string('blessing_price_list')->nullable()->after('blessing_token');
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('operator')->index();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        foreach (self::COMPANY_SCOPED as $table) {
            if (! Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
                });
            }
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel')->default('web')->index();
            $table->string('actor_name')->nullable();
            $table->string('action')->index();
            $table->string('resource_type')->nullable()->index();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('request_id')->nullable()->index();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('mcp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('client_id')->nullable()->index();
            $table->string('client_name')->nullable();
            $table->json('company_ids')->nullable();
            $table->json('scopes')->nullable();
            $table->string('token_hash')->nullable()->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcp_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('payload_hash')->nullable();
            $table->timestamp('authorized_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('business_events', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('company_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->boolean('is_root')->default(false)->index()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'is_root']);
        });

        Schema::dropIfExists('business_events');
        Schema::dropIfExists('mcp_authorizations');
        Schema::dropIfExists('mcp_connections');
        Schema::dropIfExists('audit_logs');

        foreach (self::COMPANY_SCOPED as $table) {
            if (Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropConstrainedForeignId('company_id'));
            }
        }

        Schema::dropIfExists('company_user');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'code', 'contact_name', 'contact_email', 'contact_phone', 'logo_path',
                'is_active', 'settings', 'blessing_base_url', 'blessing_token', 'blessing_price_list',
            ]);
        });
    }
};
