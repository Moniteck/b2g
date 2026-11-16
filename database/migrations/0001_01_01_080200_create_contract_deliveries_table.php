<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Contratos
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default('1.00');
            $table->string('unit')->default('Unidad');
            $table->date('due_date')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('status')->default('Pendiente');
            $table->foreignId('evidence_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('due_date');
            $table->index('status');
            $table->index(['contract_id', 'status'], 'ix_contract_deliveries_contract_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_deliveries');
    }
};
