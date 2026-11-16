<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Calendario y recordatorios
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('channel');
            $table->string('offset_key', 20)->nullable();
            $table->string('title')->nullable();
            $table->string('body', 500)->nullable();
            $table->string('href')->nullable();
            $table->string('recipient');
            $table->string('status')->default('Programado');
            $table->timestamp('scheduled_for');
            $table->dateTime('due_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->string('idempotency_key');
            $table->timestamps();
            $table->index(['company_id', 'status', 'scheduled_for'], 'ix_reminder_deliveries_company_id_status_scheduled_for');
            $table->index(['user_id', 'company_id', 'channel', 'status', 'sent_at'], 'reminder_deliveries_bell_index');
            $table->index('channel');
            $table->index(['status', 'scheduled_for'], 'reminder_deliveries_due_index');
            $table->index(['event_id', 'channel', 'recipient']);
            $table->unique('idempotency_key');
            $table->index('scheduled_for');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_deliveries');
    }
};
