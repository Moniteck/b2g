<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('remindable')->default(true)->after('priority');
            $table->json('reminder_offsets')->nullable()->after('remindable');
            $table->json('recipients')->nullable()->after('reminder_offsets');
            $table->string('origin')->default('manual')->index()->after('recipients');
            $table->string('reference_type')->nullable()->change();
            $table->unsignedBigInteger('reference_id')->nullable()->change();
            $table->index(['company_id', 'starts_at']);
        });

        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('event_type')->index();
            $table->json('days_before');
            $table->json('channels');
            $table->json('recipients')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['company_id', 'event_type'], 'reminder_rules_company_type_unique');
        });

        Schema::create('reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel')->index();
            $table->string('recipient');
            $table->string('status')->default('Programado')->index();
            $table->timestamp('scheduled_for')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['event_id', 'channel', 'recipient']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_deliveries');
        Schema::dropIfExists('reminder_rules');

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'starts_at']);
            $table->dropColumn(['remindable', 'reminder_offsets', 'recipients', 'origin']);
        });
    }
};
