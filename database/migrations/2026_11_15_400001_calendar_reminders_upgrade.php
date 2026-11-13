<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6: calendario, recordatorios y campana.
 *
 * - Eventos derivados con clave estable por origen (`source_key`): se actualizan en su sitio.
 * - Responsable, creador, «atendido» (quién y cuándo) y cancelación por cierre del origen.
 * - Entregas por usuario (`user_id`), con anticipación (`offset_key`) y plazo (`due_at`) congelados.
 * - Hora de envío por regla.
 * - El canal «bandeja» pasa a llamarse «campana»; las entregas pendientes antiguas se cancelan
 *   (tenían correos como destinatario y se reencolaban sin fin) y las regenera el planificador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('source_key', 120)->nullable()->unique()->after('origin');
            $table->string('label')->nullable()->after('title');
            $table->foreignId('contract_id')->nullable()->after('tender_id')->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
            $table->boolean('all_day')->default(false)->after('ends_at');
            $table->dateTime('attended_at')->nullable()->after('completed_at');
            $table->foreignId('attended_by')->nullable()->after('attended_at')->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable()->after('attended_by');
            $table->string('cancel_reason')->nullable()->after('cancelled_at');
            $table->index(['reference_type', 'reference_id'], 'events_reference_index');
            $table->index(['company_id', 'type', 'starts_at'], 'events_company_type_starts_index');
        });

        Schema::table('reminder_deliveries', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('company_id')->constrained()->cascadeOnDelete();
            $table->string('offset_key', 20)->nullable()->after('channel');
            $table->dateTime('due_at')->nullable()->after('scheduled_for');
            $table->index(['status', 'scheduled_for'], 'reminder_deliveries_due_index');
            $table->index(['user_id', 'company_id', 'channel', 'status', 'sent_at'], 'reminder_deliveries_bell_index');
        });

        Schema::table('reminder_rules', function (Blueprint $table) {
            $table->string('send_time', 5)->default('07:00')->after('days_before');
        });

        DB::table('reminder_deliveries')->where('channel', 'bandeja')->update(['channel' => 'campana']);
        DB::table('reminder_deliveries')
            ->whereIn('status', ['Programado', 'Encolado'])
            ->update(['status' => 'Cancelado', 'error' => 'Reemplazado por el planificador de la Fase 6.', 'updated_at' => now()]);

        // Los eventos derivados antiguos no tienen clave estable: se retiran del calendario y el
        // comando `calendario:sincronizar` los vuelve a generar desde sus registros de origen.
        DB::table('events')
            ->where('origin', '!=', 'manual')
            ->whereNull('source_key')
            ->update(['cancelled_at' => now(), 'cancel_reason' => 'Reemplazado por la sincronización de la Fase 6.']);

        DB::table('reminder_rules')->get(['id', 'channels'])->each(function ($rule) {
            $channels = json_decode((string) $rule->channels, true) ?: [];
            $channels = array_values(array_unique(array_map(fn ($c) => $c === 'bandeja' ? 'campana' : $c, $channels)));
            DB::table('reminder_rules')->where('id', $rule->id)->update(['channels' => json_encode($channels)]);
        });
    }

    public function down(): void
    {
        Schema::table('reminder_rules', function (Blueprint $table) {
            $table->dropColumn('send_time');
        });

        Schema::table('reminder_deliveries', function (Blueprint $table) {
            $table->dropIndex('reminder_deliveries_due_index');
            $table->dropIndex('reminder_deliveries_bell_index');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['offset_key', 'due_at']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_reference_index');
            $table->dropIndex('events_company_type_starts_index');
            $table->dropUnique(['source_key']);
            $table->dropConstrainedForeignId('contract_id');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('attended_by');
            $table->dropColumn(['source_key', 'label', 'all_day', 'attended_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
