<?php

namespace App\Services\Tenders;

use App\Models\Opportunity;
use App\Models\Tender;
use App\Models\User;
use App\Services\System\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Una oportunidad por licitación (decisión 13, hallazgo B-18).
 *
 * Antes de crear una oportunidad para una licitación se busca, en este orden:
 *   1. la que ya está enlazada a la licitación;
 *   2. una enlazada a otra licitación de la empresa con el mismo expediente (ignorando guiones y
 *      espacios: la misma captura importada dos veces);
 *   3. una suelta (sin licitación) cuyo título contiene el expediente o es el mismo objeto.
 * Si encuentra una suelta, la enlaza; si no encuentra ninguna, crea una en «Prospecto».
 *
 *   $result = app(TenderOpportunityLinker::class)->ensure($tender, $user);
 *   // ['opportunity' => Opportunity, 'created' => bool, 'reused' => 'tender'|'reference'|'title'|null]
 */
class TenderOpportunityLinker
{
    public function __construct(protected AuditLogger $audit) {}

    /**
     * @return array{opportunity: Opportunity|null, created: bool, reused: string|null}
     */
    public function find(Tender $tender): array
    {
        $own = Opportunity::query()->where('tender_id', $tender->id)->oldest('id')->first();
        if ($own) {
            return ['opportunity' => $own, 'created' => false, 'reused' => 'tender'];
        }

        $compact = TextMatcher::compact((string) $tender->reference);
        if ($compact !== '') {
            $siblings = Tender::query()->whereKeyNot($tender->id)->get(['id', 'reference'])
                ->filter(fn (Tender $other) => TextMatcher::compact((string) $other->reference) === $compact)->pluck('id');

            if ($siblings->isNotEmpty() && ($byReference = Opportunity::query()->whereIn('tender_id', $siblings)->oldest('id')->first())) {
                return ['opportunity' => $byReference, 'created' => false, 'reused' => 'reference'];
            }
        }

        $loose = Opportunity::query()->whereNull('tender_id')
            ->where(fn ($query) => $query
                ->when(filled($tender->reference), fn ($q) => $q->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $tender->reference).'%'))
                ->orWhere('title', Str::limit((string) $tender->title, 250, '')))
            ->when($tender->institution_id, fn ($query) => $query->where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $tender->institution_id)))
            ->oldest('id')->first();

        return ['opportunity' => $loose, 'created' => false, 'reused' => $loose ? 'title' : null];
    }

    /**
     * Devuelve la oportunidad de la licitación, reutilizando la que exista o creando una.
     * Debe llamarse dentro de una transacción con la licitación bloqueada para no duplicar.
     *
     * @return array{opportunity: Opportunity, created: bool, reused: string|null}
     */
    public function ensure(Tender $tender, User $user): array
    {
        return DB::transaction(function () use ($tender, $user) {
            Tender::query()->whereKey($tender->id)->lockForUpdate()->first();

            $found = $this->find($tender);

            if ($found['opportunity'] !== null) {
                if ($found['reused'] === 'title') {
                    $found['opportunity']->forceFill(['tender_id' => $tender->id])->save();
                    $this->audit->record('oportunidad.enlazada_a_licitacion', $found['opportunity'], ['licitacion' => $tender->reference], $user);
                }

                return $found;
            }

            $opportunity = Opportunity::create([
                'company_id' => $tender->company_id,
                'tender_id' => $tender->id,
                'institution_id' => $tender->institution_id,
                'owner_id' => $tender->owner_id ?? $user->id,
                'title' => Str::limit((string) (TenderText::display($tender->title) ?? $tender->reference), 250, ''),
                'opportunity_type' => $tender->purchase_type,
                'amount' => $tender->estimated_amount ?? 0,
                'probability' => 10,
                'stage' => 'prospecto',
                'expected_close_date' => $tender->presentation_deadline?->toDateString(),
            ]);

            $this->audit->record('oportunidad.creada_desde_licitacion', $opportunity, ['licitacion' => $tender->reference], $user);

            return ['opportunity' => $opportunity, 'created' => true, 'reused' => null];
        });
    }
}
