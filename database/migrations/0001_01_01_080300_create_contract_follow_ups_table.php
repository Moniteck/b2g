<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Contratos
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type')->default('Seguimiento');
            $table->text('description');
            $table->timestamp('occurred_at');
            $table->string('next_action')->nullable();
            $table->date('next_action_at')->nullable();
            $table->timestamps();
            $table->index('next_action_at');
            $table->index(['contract_id', 'occurred_at'], 'ix_contract_follow_ups_contract_id_occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_follow_ups');
    }
};
