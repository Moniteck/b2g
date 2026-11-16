<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Finanzas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revolving_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->decimal('initial_amount', 14, 2)->default('0.00');
            $table->decimal('current_balance', 14, 2)->default('0.00');
            $table->timestamps();
            $table->unique('company_id', 'uq_revolving_budgets_company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revolving_budgets');
    }
};
