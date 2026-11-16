<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Finanzas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_cash_incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('concept');
            $table->decimal('amount', 14, 2);
            $table->date('received_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'received_at'], 'ix_manual_cash_incomes_company_id_received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_cash_incomes');
    }
};
