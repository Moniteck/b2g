<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Licitaciones
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->string('purchasing_unit')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference');
            $table->string('process_name')->nullable();
            $table->string('title', 1000);
            $table->string('purchase_type')->nullable();
            $table->string('funding_source_type', 120)->nullable();
            $table->string('funding_source', 120)->nullable();
            $table->string('honducompras_stage', 120)->nullable();
            $table->string('acquisition_type', 120)->nullable();
            $table->string('status')->default('Activa');
            $table->decimal('estimated_amount', 14, 2)->default('0.00');
            $table->decimal('bid_document_fee', 14, 2)->nullable();
            $table->unsignedSmallInteger('offer_validity_days')->nullable();
            $table->date('published_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('clarification_deadline')->nullable();
            $table->dateTime('presentation_deadline')->nullable();
            $table->dateTime('opening_at')->nullable();
            $table->string('offer_reception_place', 500)->nullable();
            $table->string('source_url')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('requirements')->nullable();
            $table->string('delivery_terms')->nullable();
            $table->string('payment_terms')->nullable();
            $table->date('closing_date')->nullable();
            $table->json('documents')->nullable();
            $table->text('observations')->nullable();
            $table->boolean('offer_submitted')->default(false);
            $table->string('result')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'closing_date'], 'ix_tenders_company_id_closing_date');
            $table->index(['company_id', 'presentation_deadline'], 'ix_tenders_company_id_presentation_deadline');
            $table->index(['company_id', 'status'], 'ix_tenders_company_id_status');
            $table->index('clarification_deadline');
            $table->index('closing_date');
            $table->index('presentation_deadline');
            $table->index('published_at');
            $table->index('result');
            $table->index(['status', 'closing_date', 'purchase_type']);
            $table->index('status');
            $table->unique(['company_id', 'reference'], 'uq_tenders_company_id_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenders');
    }
};
