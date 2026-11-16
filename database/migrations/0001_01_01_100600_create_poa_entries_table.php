<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Finanzas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poa_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->string('project');
            $table->unsignedTinyInteger('probability')->default(0);
            $table->decimal('budgeted_amount', 14, 2)->default('0.00');
            $table->decimal('executed_amount', 14, 2)->default('0.00');
            $table->date('estimated_date')->nullable();
            $table->string('status')->default('Identificada');
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('year')->default(2026);
            $table->string('budget_line')->nullable();
            $table->unsignedTinyInteger('quarter')->nullable();
            $table->boolean('annual_close_alert')->default(false);
            $table->timestamps();
            $table->index(['company_id', 'estimated_date'], 'ix_poa_entries_company_id_estimated_date');
            $table->index(['company_id', 'year', 'status'], 'ix_poa_entries_company_id_year_status');
            $table->index('estimated_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poa_entries');
    }
};
