<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Finanzas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('moved_at');
            $table->string('type');
            $table->string('concept');
            $table->string('reference')->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2)->nullable();
            $table->foreignId('revolving_budget_id')->nullable()->constrained('revolving_budgets')->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->boolean('synchronized')->default(false);
            $table->timestamps();
            $table->index('moved_at');
            $table->index(['source_type', 'source_id']);
            $table->index(['company_id', 'moved_at'], 'ix_budget_movements_company_id_moved_at');
            $table->unique(['company_id', 'reference'], 'uq_budget_movements_company_id_reference');
            $table->unique(['company_id', 'source_type', 'source_id'], 'uq_budget_movements_company_id_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_movements');
    }
};
