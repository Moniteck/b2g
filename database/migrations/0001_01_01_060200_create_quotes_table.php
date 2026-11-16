<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Comercial: pipeline, cotizaciones y clientes
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
            $table->foreignId('tender_id')->nullable()->constrained('tenders')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number');
            $table->date('quoted_at');
            $table->string('purchase_type');
            $table->unsignedSmallInteger('validity_days')->default(30);
            $table->string('status')->default('Borrador');
            $table->string('outcome')->default('Pendiente');
            $table->decimal('awarded_amount', 14, 2)->nullable();
            $table->date('awarded_at')->nullable();
            $table->string('winner_name')->nullable();
            $table->text('lost_reason')->nullable();
            $table->unsignedSmallInteger('revision')->default(1);
            $table->decimal('subtotal', 14, 2)->default('0.00');
            $table->decimal('tax', 14, 2)->default('0.00');
            $table->decimal('discount', 14, 2)->default('0.00');
            $table->boolean('tax_included')->default(true);
            $table->string('pricing_source')->default('manual');
            $table->decimal('total', 14, 2)->default('0.00');
            $table->text('notes')->nullable();
            $table->text('conditions')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'institution_id'], 'ix_quotes_company_id_institution_id');
            $table->index(['company_id', 'opportunity_id'], 'ix_quotes_company_id_opportunity_id');
            $table->index(['company_id', 'outcome'], 'ix_quotes_company_id_outcome');
            $table->index(['company_id', 'quoted_at'], 'ix_quotes_company_id_quoted_at');
            $table->index(['company_id', 'status'], 'ix_quotes_company_id_status');
            $table->index(['company_id', 'tender_id'], 'ix_quotes_company_id_tender_id');
            $table->index('outcome');
            $table->index(['quoted_at', 'purchase_type', 'status']);
            $table->index('status');
            $table->unique(['company_id', 'number'], 'uq_quotes_company_id_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
