<?php

namespace App\Services\Tenders;

use App\Models\Institution;

/**
 * Busca en el catálogo de instituciones la que corresponde a la «Entidad» leída.
 *
 * Compara por nombre (similitud aproximada) y por siglas: «… Transporte (SIT)» empareja con la
 * institución de siglas «SIT». Devuelve los mejores candidatos; la pantalla de revisión
 * preselecciona el primero solo si es claro.
 */
class InstitutionMatcher
{
    public const CLEAR_MATCH = 0.85;

    /**
     * @return array<int, array{id: int, name: string, acronym: ?string, score: float}>
     */
    public function candidates(?string $entity, int $limit = 5): array
    {
        $entity = trim((string) $entity);

        if ($entity === '') {
            return [];
        }

        $acronym = preg_match('/\(([A-ZÁÉÍÓÚÑ0-9\-]{2,15})\)\s*$/u', $entity, $match) ? TextMatcher::compact($match[1]) : null;
        $withoutAcronym = trim((string) preg_replace('/\([^)]*\)\s*$/u', '', $entity));

        return Institution::query()
            ->select(['id', 'name', 'acronym'])
            ->orderBy('name')
            ->limit(5000)
            ->get()
            ->map(function (Institution $institution) use ($entity, $withoutAcronym, $acronym) {
                $score = max(
                    TextMatcher::similarity($entity, $institution->name),
                    TextMatcher::similarity($withoutAcronym, (string) preg_replace('/\([^)]*\)\s*$/u', '', $institution->name)),
                );

                $ownAcronym = $institution->acronym ?: (preg_match('/\(([^)]+)\)\s*$/u', $institution->name, $m) ? $m[1] : null);

                if ($acronym !== null && $ownAcronym !== null && TextMatcher::compact($ownAcronym) === $acronym) {
                    $score = max($score, 0.9);
                }

                return ['id' => $institution->id, 'name' => $institution->name, 'acronym' => $institution->acronym, 'score' => round($score, 3)];
            })
            ->filter(fn (array $candidate) => $candidate['score'] >= 0.55)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }
}
