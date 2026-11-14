<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline (Fase 8):
 * - Índice para el filtro por responsable del tablero.
 * - `opportunities.quote_id` nunca se escribía: se completa con la cotización ganada de la
 *   oportunidad o, si no hay, con su cotización viva más reciente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasIndex('opportunities', 'ix_opportunities_company_id_owner_id')) {
            Schema::table('opportunities', function (Blueprint $table) {
                $table->index(['company_id', 'owner_id'], 'ix_opportunities_company_id_owner_id');
            });
        }

        DB::table('opportunities')->whereNull('quote_id')->select('id')
            ->chunkById(200, function ($opportunities) {
                foreach ($opportunities as $opportunity) {
                    $quoteId = DB::table('quotes')->where('opportunity_id', $opportunity->id)->whereNull('deleted_at')
                        ->whereNotIn('status', ['Sustituida', 'Anulada'])
                        ->orderByRaw("CASE WHEN outcome = 'Ganada' THEN 0 ELSE 1 END")
                        ->orderByDesc('id')
                        ->value('id');

                    if ($quoteId !== null) {
                        DB::table('opportunities')->where('id', $opportunity->id)->update(['quote_id' => $quoteId]);
                    }
                }
            });
    }

    public function down(): void
    {
        if ($this->hasIndex('opportunities', 'ix_opportunities_company_id_owner_id')) {
            Schema::table('opportunities', function (Blueprint $table) {
                $table->dropIndex('ix_opportunities_company_id_owner_id');
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['name'] === $name);
    }
};
