<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Herramientas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relation_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institutional_relation_id')->constrained('institutional_relations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->comment('Usuario que registró la interacción')->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->text('description');
            $table->dateTime('occurred_at');
            $table->string('result')->nullable();
            $table->date('next_action_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relation_interactions');
    }
};
