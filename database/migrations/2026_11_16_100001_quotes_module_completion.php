<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizaciones completas (Fase 7).
 *
 * - `quotes.conditions`: condiciones de la oferta (entrega, pago, garantía) que salen en el PDF.
 * - `quote_items`: base del precio (`tax_included`), descuento porcentual, subtotal sin ISV,
 *   impuesto de la línea, SKU y orden. `total` sigue siendo el total de la línea con ISV.
 * - `quote_participants`: se vinculan también a la oportunidad (el kanban los usa sin
 *   cotización), `quote_id` pasa a opcional y se marca el ganador (`is_winner`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'conditions')) {
                $table->text('conditions')->nullable()->after('notes');
            }
        });

        Schema::table('quote_items', function (Blueprint $table) {
            if (! Schema::hasColumn('quote_items', 'sku')) {
                $table->string('sku', 100)->nullable()->after('inventory_item_id');
            }
            if (! Schema::hasColumn('quote_items', 'tax_included')) {
                $table->boolean('tax_included')->default(false)->after('tax_rate');
            }
            if (! Schema::hasColumn('quote_items', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->default(0)->after('unit_price');
            }
            if (! Schema::hasColumn('quote_items', 'subtotal')) {
                $table->decimal('subtotal', 14, 2)->default(0)->after('discount_percent');
            }
            if (! Schema::hasColumn('quote_items', 'tax')) {
                $table->decimal('tax', 14, 2)->default(0)->after('subtotal');
            }
            if (! Schema::hasColumn('quote_items', 'position')) {
                $table->unsignedSmallInteger('position')->default(0)->after('quote_id');
            }
        });

        // Las líneas anteriores guardaban precios con ISV incluido y total = cantidad × precio.
        DB::table('quote_items')->where('subtotal', 0)->where('total', '>', 0)->orderBy('id')
            ->each(function (object $item) {
                $rate = (float) ($item->tax_rate ?? 0);
                $subtotal = $rate > 0 ? round((float) $item->total / (1 + $rate / 100), 2) : (float) $item->total;
                DB::table('quote_items')->where('id', $item->id)->update([
                    'tax_included' => true,
                    'subtotal' => $subtotal,
                    'tax' => round((float) $item->total - $subtotal, 2),
                ]);
            });

        Schema::table('quote_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('quote_participants', 'opportunity_id')) {
                $table->foreignId('opportunity_id')->nullable()->after('quote_id')
                    ->constrained('opportunities')->cascadeOnDelete();
                $table->index(['opportunity_id', 'position'], 'ix_quote_participants_opportunity_id_position');
            }
            if (! Schema::hasColumn('quote_participants', 'is_winner')) {
                $table->boolean('is_winner')->default(false)->after('is_ours');
            }
        });

        Schema::table('quote_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('quote_id')->nullable()->change();
        });

        // Los participantes existentes de una cotización con oportunidad quedan también en ella.
        DB::table('quote_participants')->whereNull('opportunity_id')->orderBy('id')
            ->each(function (object $participant) {
                $opportunityId = DB::table('quotes')->where('id', $participant->quote_id)->value('opportunity_id');
                if ($opportunityId !== null) {
                    DB::table('quote_participants')->where('id', $participant->id)->update(['opportunity_id' => $opportunityId]);
                }
            });

        Schema::table('contracts', function (Blueprint $table) {
            $table->index(['company_id', 'quote_id'], 'ix_contracts_company_id_quote_id');
            $table->index(['company_id', 'opportunity_id'], 'ix_contracts_company_id_opportunity_id');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->index(['company_id', 'opportunity_id'], 'ix_quotes_company_id_opportunity_id');
            $table->index(['company_id', 'tender_id'], 'ix_quotes_company_id_tender_id');
            $table->index(['company_id', 'institution_id'], 'ix_quotes_company_id_institution_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex('ix_quotes_company_id_opportunity_id');
            $table->dropIndex('ix_quotes_company_id_tender_id');
            $table->dropIndex('ix_quotes_company_id_institution_id');
            $table->dropColumn('conditions');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('ix_contracts_company_id_quote_id');
            $table->dropIndex('ix_contracts_company_id_opportunity_id');
        });

        Schema::table('quote_participants', function (Blueprint $table) {
            $table->dropIndex('ix_quote_participants_opportunity_id_position');
            $table->dropConstrainedForeignId('opportunity_id');
            $table->dropColumn('is_winner');
        });

        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropColumn(['sku', 'tax_included', 'discount_percent', 'subtotal', 'tax', 'position']);
        });
    }
};
