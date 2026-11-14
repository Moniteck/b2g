<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->date('last_purchase_at')->nullable()->after('accumulated_amount');
            $table->unique('tax_id');
        });
        Schema::table('institutions', function (Blueprint $table) {
            $table->string('fleet_type')->nullable()->after('type');
            $table->string('modality')->nullable()->after('fleet_type');
            $table->string('contact_name')->nullable()->after('modality');
            $table->string('phone')->nullable()->after('contact_name');
            $table->string('email')->nullable()->after('phone');
            $table->string('status')->default('Activa')->index()->after('location');
            $table->text('management_notes')->nullable()->after('notes');
            $table->string('source')->nullable()->after('management_notes');
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('department')->nullable()->after('position');
            $table->string('whatsapp')->nullable()->after('phone');
        });
        Schema::table('opportunities', function (Blueprint $table) {
            $table->string('opportunity_type')->nullable()->after('title');
            $table->string('next_action')->nullable()->after('stage');
        });
        Schema::table('tenders', function (Blueprint $table) {
            $table->date('published_at')->nullable()->index()->after('estimated_amount');
            $table->json('documents')->nullable()->after('closing_date');
            $table->text('observations')->nullable()->after('documents');
            $table->boolean('offer_submitted')->default(false)->after('observations');
            $table->string('result')->nullable()->index()->after('offer_submitted');
            $table->index(['status', 'closing_date', 'purchase_type']);
        });
        Schema::table('process_histories', function (Blueprint $table) {
            $table->string('category')->nullable()->after('modality');
            $table->boolean('participated')->default(false)->after('category');
            $table->string('decision')->nullable()->after('participated');
            $table->json('result_metadata')->nullable()->after('result');
        });
        Schema::table('quotes', function (Blueprint $table) {
            $table->index(['quoted_at', 'purchase_type', 'status']);
        });
        Schema::create('delivery_alarms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('Pendiente')->index();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['quote_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_alarms');
        Schema::table('quotes', fn (Blueprint $table) => $table->dropIndex(['quoted_at', 'purchase_type', 'status']));
        Schema::table('process_histories', fn (Blueprint $table) => $table->dropColumn(['category', 'participated', 'decision', 'result_metadata']));
        Schema::table('tenders', fn (Blueprint $table) => $table->dropColumn(['published_at', 'documents', 'observations', 'offer_submitted', 'result']));
        Schema::table('opportunities', fn (Blueprint $table) => $table->dropColumn(['opportunity_type', 'next_action']));
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn(['department', 'whatsapp']));
        Schema::table('institutions', fn (Blueprint $table) => $table->dropColumn(['fleet_type', 'modality', 'contact_name', 'phone', 'email', 'status', 'management_notes', 'source']));
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['tax_id']);
            $table->dropColumn('last_purchase_at');
        });
    }
};
