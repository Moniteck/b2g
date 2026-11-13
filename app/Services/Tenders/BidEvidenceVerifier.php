<?php

namespace App\Services\Tenders;

use App\Services\Ai\BidDocumentAnalyzer;

/**
 * Comprueba en el servidor que cada cita devuelta por la IA aparece de verdad en el pliego.
 *
 * Estados de cada dato:
 *   verified    la cita está en la página indicada (o en otra, y se corrige la página)
 *   doubtful    la cita está, pero el valor no cuadra con ella (p. ej. otra fecha)
 *   unverified  «sin evidencia»: la cita no aparece; nunca se aplica sola
 *
 * La comparación tolera espacios, tildes, mayúsculas y ruido de escaneo (TextMatcher::containment).
 */
class BidEvidenceVerifier
{
    public const VERIFIED = 'verified';

    public const DOUBTFUL = 'doubtful';

    public const UNVERIFIED = 'unverified';

    /** Similitud mínima entre la cita y el texto de la página. */
    public const THRESHOLD = 0.85;

    /**
     * @param  array{fields: array<string, array<int, array<string, mixed>>>, lists: array<string, array<int, array<string, mixed>>>}  $analysis
     * @param  array<int, string>  $pages
     * @return array{fields: array<string, array<string, mixed>|null>, lists: array<string, array<int, array<string, mixed>>>}
     */
    public function verify(array $analysis, array $pages): array
    {
        $fields = [];

        foreach (array_keys(BidDocumentAnalyzer::FIELDS) as $key) {
            $candidates = array_map(fn (array $candidate) => $this->check($key, $candidate, $pages), $analysis['fields'][$key] ?? []);
            $fields[$key] = $this->choose($candidates);
        }

        $lists = [];

        foreach (array_keys(BidDocumentAnalyzer::LISTS) as $key) {
            $lists[$key] = array_map(fn (array $item) => $this->locate($item, $pages), $analysis['lists'][$key] ?? []);
        }

        return ['fields' => $fields, 'lists' => $lists];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, string>  $pages
     * @return array<string, mixed>
     */
    public function check(string $key, array $candidate, array $pages): array
    {
        $candidate = $this->locate($candidate, $pages);

        if (in_array($key, BidDocumentAnalyzer::DATE_FIELDS, true)) {
            $candidate = $this->checkDate($candidate);
        }

        if ($key === 'offer_validity_days') {
            $days = filter_var($candidate['value'], FILTER_VALIDATE_INT);
            $candidate['value'] = $days !== false && $days > 0 && $days <= 3650 ? $days : null;

            if ($candidate['value'] === null) {
                $candidate['status'] = self::UNVERIFIED;
                $candidate['note'] = 'La vigencia no es un número de días válido.';
            }
        }

        return $candidate;
    }

    /**
     * Busca la cita en su página; si no está, en las vecinas y luego en todo el pliego.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $pages
     * @return array<string, mixed>
     */
    public function locate(array $item, array $pages): array
    {
        $quote = trim((string) ($item['quote'] ?? ''));
        $page = $item['page'] ?? null;
        $item['score'] = 0.0;
        $item['status'] = self::UNVERIFIED;

        // Una cita de menos de tres palabras no demuestra nada: puede estar en cualquier lado.
        if (count(explode(' ', TextMatcher::normalize($quote))) < 3) {
            $item['note'] = $item['note'] ?? 'Sin cita suficiente para comprobar el dato.';

            return $item;
        }

        $order = [];
        if (is_int($page) && isset($pages[$page - 1])) {
            $order = [$page, $page - 1, $page + 1];
        }
        $order = array_values(array_unique(array_merge($order, range(1, count($pages)))));

        foreach ($order as $candidatePage) {
            if (! isset($pages[$candidatePage - 1])) {
                continue;
            }

            $score = TextMatcher::containment($quote, $pages[$candidatePage - 1]);

            if ($score >= self::THRESHOLD) {
                $item['score'] = round($score, 3);
                $item['status'] = self::VERIFIED;

                if ($candidatePage !== $page) {
                    $item['note'] = trim(($item['note'] ?? '').' La cita está en la página '.$candidatePage.($page ? ", no en la {$page}." : '.'));
                    $item['page'] = $candidatePage;
                }

                return $item;
            }

            $item['score'] = max($item['score'], round($score, 3));
        }

        $item['note'] = trim(($item['note'] ?? '').' La cita no aparece en el pliego: sin evidencia.');

        return $item;
    }

    /**
     * La fecha propuesta debe tener formato válido y coincidir con lo que dice la cita.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function checkDate(array $candidate): array
    {
        $date = SpanishDates::iso((string) $candidate['value']);

        if ($date === null) {
            $candidate['status'] = self::UNVERIFIED;
            $candidate['note'] = 'La IA devolvió una fecha con formato no válido.';
            $candidate['value'] = null;

            return $candidate;
        }

        $hasTime = strlen(trim((string) $candidate['value'])) > 10;
        $candidate['value'] = $hasTime ? $date->format('Y-m-d\TH:i') : $date->format('Y-m-d');

        if ($candidate['status'] !== self::VERIFIED) {
            return $candidate;
        }

        $fromQuote = SpanishDates::prose((string) $candidate['quote']) ?? $this->numericFrom((string) $candidate['quote']);

        if ($fromQuote !== null && $fromQuote['date'] !== $date->format('Y-m-d')) {
            $candidate['status'] = self::DOUBTFUL;
            $candidate['note'] = trim(($candidate['note'] ?? '').' La cita parece decir '.$fromQuote['date'].'; revisa la fecha.');
        } elseif ($fromQuote !== null && $fromQuote['time'] !== null && $hasTime && $fromQuote['time'] !== $date->format('H:i')) {
            $candidate['status'] = self::DOUBTFUL;
            $candidate['note'] = trim(($candidate['note'] ?? '').' La cita indica la hora '.$fromQuote['time'].'; revisa la hora.');
        }

        return $candidate;
    }

    /**
     * @return array{date: string, time: ?string}|null
     */
    private function numericFrom(string $quote): ?array
    {
        $numeric = SpanishDates::numeric($quote);

        return $numeric === null ? null : ['date' => substr($numeric['value'], 0, 10), 'time' => SpanishDates::time($quote)];
    }

    /**
     * Elige la mejor propuesta: verificada antes que dudosa antes que sin evidencia.
     * Las demás con otro valor quedan como alternativas para que la persona elija.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function choose(array $candidates): ?array
    {
        $candidates = array_values(array_filter($candidates, fn ($candidate) => $candidate['value'] !== null && $candidate['value'] !== ''));

        if ($candidates === []) {
            return null;
        }

        $rank = [self::VERIFIED => 0, self::DOUBTFUL => 1, self::UNVERIFIED => 2];
        usort($candidates, fn ($a, $b) => [$rank[$a['status']], -$a['score']] <=> [$rank[$b['status']], -$b['score']]);

        $best = array_shift($candidates);
        $best['alternatives'] = array_values(array_filter(
            $candidates,
            fn ($candidate) => (string) $candidate['value'] !== (string) $best['value'],
        ));

        return $best;
    }
}
