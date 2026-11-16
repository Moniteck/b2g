<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Calendario y recordatorios
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('event_type');
            $table->json('days_before');
            $table->time('send_time')->default('07:00:00');
            $table->json('channels');
            $table->json('recipients')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'event_type'], 'reminder_rules_company_type_unique');
            $table->index('event_type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_rules');
    }
};
