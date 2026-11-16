<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Calendario y recordatorios
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
            $table->foreignId('tender_id')->nullable()->constrained('tenders')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('title');
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->string('type')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('priority')->default('Normal');
            $table->boolean('remindable')->default(true);
            $table->json('reminder_offsets')->nullable();
            $table->json('recipients')->nullable();
            $table->string('origin')->default('manual');
            $table->string('source_key', 120)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->dateTime('attended_at')->nullable();
            $table->foreignId('attended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'starts_at']);
            $table->index(['company_id', 'type', 'starts_at'], 'events_company_type_starts_index');
            $table->index('origin');
            $table->index(['reference_type', 'reference_id'], 'events_reference_index');
            $table->unique('source_key');
            $table->index('starts_at');
            $table->index(['starts_at', 'type', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
