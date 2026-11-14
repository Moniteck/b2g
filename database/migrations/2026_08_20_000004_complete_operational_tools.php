<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarantees', function (Blueprint $t) {
            $t->decimal('percentage', 5, 2)->default(0);
            $t->string('currency', 3)->default('HNL');
            $t->date('released_at')->nullable();
            $t->date('executed_at')->nullable();
        });
        Schema::table('contracts', function (Blueprint $t) {
            $t->decimal('retention_amount', 14, 2)->default(0);
            $t->decimal('financial_progress', 5, 2)->default(0);
            $t->date('completed_at')->nullable();
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->string('number');
            $t->decimal('amount', 14, 2);
            $t->date('issued_at');
            $t->string('status')->default('Pendiente');
            $t->timestamps();
            $t->unique(['contract_id', 'number']);
        });
        Schema::create('delivery_certificates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->string('number');
            $t->decimal('amount', 14, 2)->default(0);
            $t->date('issued_at');
            $t->string('status')->default('Pendiente');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['contract_id', 'number']);
        });
        Schema::table('competitors', function (Blueprint $t) {
            $t->string('tax_id')->nullable()->unique();
            $t->string('category')->nullable();
            $t->decimal('average_award_price', 14, 2)->nullable();
            $t->date('last_participation_at')->nullable();
        });
        Schema::table('institutional_relations', function (Blueprint $t) {
            $t->string('influence')->nullable();
            $t->string('channel')->nullable();
            $t->string('status')->default('Activa');
            $t->date('started_at')->nullable();
            $t->string('poa_interest')->nullable();
        });
        Schema::table('relation_interactions', function (Blueprint $t) {
            $t->string('result')->nullable();
            $t->date('next_action_at')->nullable();
        });
        Schema::table('collection_invoices', function (Blueprint $t) {
            $t->decimal('interest_rate', 5, 2)->default(0);
            $t->text('collection_note')->nullable();
            $t->timestamp('payment_recorded_at')->nullable();
        });
        Schema::create('revolving_budgets', function (Blueprint $t) {
            $t->id();
            $t->decimal('initial_amount', 14, 2)->default(0);
            $t->decimal('current_balance', 14, 2)->default(0);
            $t->timestamps();
        });
        Schema::table('budget_movements', function (Blueprint $t) {
            $t->foreignId('revolving_budget_id')->nullable()->constrained()->nullOnDelete();
            $t->string('source_type')->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->boolean('synchronized')->default(false);
            $t->index(['source_type', 'source_id']);
        });
        Schema::create('manual_cash_incomes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('concept');
            $t->decimal('amount', 14, 2);
            $t->date('received_at');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::table('poa_entries', function (Blueprint $t) {
            $t->unsignedSmallInteger('year')->default(now()->year);
            $t->string('partida')->nullable();
            $t->unsignedTinyInteger('quarter')->nullable();
            $t->boolean('annual_close_alert')->default(false);
        });
        Schema::table('consortia', function (Blueprint $t) {
            $t->foreignId('tender_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('leader_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $t->decimal('amount', 14, 2)->default(0);
            $t->date('formed_at')->nullable();
        });
        Schema::table('consortium_members', function (Blueprint $t) {
            $t->string('tax_id')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
        });
        Schema::table('documents', function (Blueprint $t) {
            $t->date('issued_at')->nullable();
            $t->string('original_filename')->nullable();
            $t->string('disk')->default('local');
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index(['status', 'expires_at']);
        });
        Schema::create('compliance_matrices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->timestamps();
            $t->unique(['tender_id', 'name']);
        });
        Schema::create('compliance_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('compliance_matrix_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $t->string('requirement');
            $t->boolean('completed')->default(false);
            $t->date('renewal_due_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_items');
        Schema::dropIfExists('compliance_matrices');
        Schema::dropIfExists('manual_cash_incomes');
        Schema::dropIfExists('delivery_certificates');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('revolving_budgets');
    }
};
