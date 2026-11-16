<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Núcleo: empresas, usuarios y acceso
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tutorial_key');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'tutorial_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_progress');
    }
};
