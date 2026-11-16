<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Contratos
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('tender_id')->nullable()->constrained('tenders')->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number');
            $table->string('name');
            $table->decimal('amount', 14, 2);
            $table->decimal('awarded_amount', 14, 2)->nullable();
            $table->date('signed_at')->nullable();
            $table->unsignedInteger('duration_days')->nullable();
            $table->boolean('duration_business_days')->default(false);
            $table->string('start_trigger')->default('Orden de inicio');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_end_date_manual')->default(false)->comment('La fecha fin la fijó una persona: no se recalcula desde el plazo');
            $table->decimal('billed_amount', 14, 2)->default('0.00');
            $table->decimal('collected_amount', 14, 2)->default('0.00');
            $table->unsignedTinyInteger('physical_progress')->default(0);
            $table->string('status')->default('En ejecución');
            $table->boolean('product_delivered')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('retention_amount', 14, 2)->default('0.00');
            $table->decimal('financial_progress', 5, 2)->default('0.00');
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index('status');
            $table->index(['company_id', 'end_date'], 'ix_contracts_company_id_end_date');
            $table->index(['company_id', 'institution_id'], 'ix_contracts_company_id_institution_id');
            $table->index(['company_id', 'manager_id'], 'ix_contracts_company_id_manager_id');
            $table->index(['company_id', 'opportunity_id'], 'ix_contracts_company_id_opportunity_id');
            $table->index(['company_id', 'quote_id'], 'ix_contracts_company_id_quote_id');
            $table->unique(['company_id', 'number'], 'uq_contracts_company_id_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
