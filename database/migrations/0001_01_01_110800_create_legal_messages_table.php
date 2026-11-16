<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Herramientas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_conversation_id')->constrained('legal_conversations')->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_messages');
    }
};
