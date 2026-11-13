<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Cotizaciones: estado documental separado del resultado comercial ---
        if (! Schema::hasColumn('quotes', 'opportunity_id')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->foreignId('opportunity_id')->nullable()->after('institution_id')->constrained('opportunities')->nullOnDelete();
                $table->foreignId('tender_id')->nullable()->after('opportunity_id')->constrained('tenders')->nullOnDelete();
                $table->string('outcome')->default('Pendiente')->index()->after('status');
                $table->decimal('awarded_amount', 14, 2)->nullable()->after('outcome');
                $table->date('awarded_at')->nullable()->after('awarded_amount');
                $table->string('winner_name')->nullable()->after('awarded_at');
                $table->text('lost_reason')->nullable()->after('winner_name');
                $table->unsignedSmallInteger('revision')->default(1)->after('lost_reason');
                $table->string('prepared_by')->nullable()->after('created_by');
            });
        }

        $this->migrateQuoteStatuses();

        if (! Schema::hasTable('quote_participants')) {
            Schema::create('quote_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('tax_id')->nullable();
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('currency', 3)->default('HNL');
                $table->boolean('includes_tax')->default(true);
                $table->boolean('is_ours')->default(false);
                $table->unsignedSmallInteger('position')->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['quote_id', 'position']);
            });
        }

        // --- Pipeline: cinco etapas ---
        if (! Schema::hasColumn('opportunities', 'tender_id')) {
            Schema::table('opportunities', function (Blueprint $table) {
                $table->foreignId('tender_id')->nullable()->after('institution_id')->constrained('tenders')->nullOnDelete();
                $table->foreignId('quote_id')->nullable()->after('tender_id')->constrained('quotes')->nullOnDelete();
                $table->string('winner_name')->nullable()->after('next_action');
                $table->decimal('awarded_amount', 14, 2)->nullable()->after('winner_name');
                $table->date('awarded_at')->nullable()->after('awarded_amount');
                $table->text('lost_reason')->nullable()->after('awarded_at');
            });
        }

        $this->migrateOpportunityStages();

        // --- Licitaciones: fechas extraidas del expediente ---
        if (! Schema::hasColumn('tenders', 'process_name')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->string('process_name')->nullable()->after('reference');
                $table->dateTime('clarification_deadline')->nullable()->index()->after('published_at');
                $table->dateTime('presentation_deadline')->nullable()->index()->after('clarification_deadline');
                $table->dateTime('opening_at')->nullable()->after('presentation_deadline');
                $table->string('source_url')->nullable()->after('opening_at');
                $table->string('contact_name')->nullable()->after('source_url');
                $table->string('contact_phone')->nullable()->after('contact_name');
                $table->string('contact_email')->nullable()->after('contact_phone');
                $table->text('requirements')->nullable()->after('contact_email');
                $table->string('delivery_terms')->nullable()->after('requirements');
                $table->string('payment_terms')->nullable()->after('delivery_terms');
            });
        }

        // --- Clientes: origen siempre adjudicacion ---
        if (! Schema::hasColumn('clients', 'source_quote_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->foreignId('source_quote_id')->nullable()->after('institution_id')->constrained('quotes')->nullOnDelete();
                $table->string('origin')->default('Adjudicacion')->index()->after('source_quote_id');
                $table->string('dedupe_key')->nullable()->unique()->after('origin');
                $table->string('contact_user_id')->nullable()->after('contact_name');
            });
        }
    }

    /**
     * Los estados documentales unifican el ciclo de vida de la oferta y el
     * resultado comercial viaja en la columna `outcome`.
     */
    private function migrateQuoteStatuses(): void
    {
        DB::table('quotes')->where('status', 'Enviada')->update(['status' => 'Presentada']);
        DB::table('quotes')->where('status', 'Aceptada')->update(['status' => 'Presentada', 'outcome' => 'Ganada']);
        DB::table('quotes')->where('status', 'Rechazada')->update(['status' => 'Presentada', 'outcome' => 'Perdida']);
    }

    private function migrateOpportunityStages(): void
    {
        $map = [
            'contactado' => 'oportunidad',
            'calificacion' => 'oportunidad',
            'propuesta' => 'oferta_presentada',
            'negociacion' => 'oferta_presentada',
            'ganada' => 'adjudicado',
            'perdido' => 'no_adjudicado',
        ];

        foreach ($map as $from => $to) {
            DB::table('opportunities')->where('stage', $from)->update(['stage' => $to]);
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_quote_id');
            $table->dropColumn(['origin', 'dedupe_key', 'contact_user_id']);
        });

        Schema::table('tenders', function (Blueprint $table) {
            $table->dropColumn([
                'process_name', 'clarification_deadline', 'presentation_deadline', 'opening_at',
                'source_url', 'contact_name', 'contact_phone', 'contact_email', 'requirements',
                'delivery_terms', 'payment_terms',
            ]);
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tender_id');
            $table->dropConstrainedForeignId('quote_id');
            $table->dropColumn(['winner_name', 'awarded_amount', 'awarded_at', 'lost_reason']);
        });

        Schema::dropIfExists('quote_participants');

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opportunity_id');
            $table->dropConstrainedForeignId('tender_id');
            $table->dropColumn([
                'outcome', 'awarded_amount', 'awarded_at', 'winner_name', 'lost_reason', 'revision', 'prepared_by',
            ]);
        });
    }
};
