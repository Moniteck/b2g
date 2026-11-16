<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Finanzas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->string('number');
            $table->string('concept');
            $table->decimal('amount', 14, 2);
            $table->date('issued_at');
            $table->date('due_at');
            $table->date('paid_at')->nullable();
            $table->string('status')->default('Pendiente');
            $table->decimal('interest_amount', 14, 2)->default('0.00');
            $table->text('notes')->nullable();
            $table->decimal('interest_rate', 5, 2)->default('0.00');
            $table->text('collection_note')->nullable();
            $table->timestamp('payment_recorded_at')->nullable();
            $table->timestamps();
            $table->index('due_at');
            $table->index('status');
            $table->index(['company_id', 'status', 'due_at'], 'ix_collection_invoices_company_id_status_due_at');
            $table->index(['company_id', 'status', 'paid_at'], 'ix_collection_invoices_company_id_status_paid_at');
            $table->unique(['company_id', 'number'], 'uq_collection_invoices_company_id_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_invoices');
    }
};
