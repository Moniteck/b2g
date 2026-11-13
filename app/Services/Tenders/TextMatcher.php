<?php

namespace App\Services\Tenders;

use Illuminate\Support\Str;

/**
 * Comparación de texto tolerante a errores de OCR y de escaneo.
 *
 *   TextMatcher::normalize('  Fecha Recepción  Ofertas ') // "fecha recepcion ofertas"
 *   TextMatcher::similarity('Admiristrativa', 'Administrativa') // 0.93
 */
final class TextMatcher
{
    /** Minúsculas, sin tildes y con todo lo que no es letra o número reducido a un espacio. */
    public static function normalize(string $text): string
    {
        $ascii = Str::ascii(mb_strtolower($text));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /** Igual que normalize() pero sin espacios: «CPR SIT-025» y «CPRSIT025» coinciden. */
    public static function compact(string $text): string
    {
        return str_replace(' ', '', self::normalize($text));
    }

    /** Similitud de 0 a 1 por distancia de edición sobre el texto normalizado. */
    public static function similarity(string $a, string $b): float
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $max = max(strlen($a), strlen($b));

        // levenshtein() de PHP admite cadenas largas, pero el costo es cuadrático: se recorta.
        if ($max > 600) {
            similar_text($a, $b, $percent);

            return $percent / 100;
        }

        return 1 - (levenshtein($a, $b) / $max);
    }

    /**
     * El valor de una lista cerrada más parecido al texto, si supera el umbral.
     *
     * @param  array<int, string>  $vocabulary
     * @return array{value: string, score: float}|null
     */
    public static function closest(string $text, array $vocabulary, float $threshold = 0.8): ?array
    {
        $best = null;

        foreach ($vocabulary as $candidate) {
            $score = self::similarity($text, $candidate);

            if ($best === null || $score > $best['score']) {
                $best = ['value' => $candidate, 'score' => $score];
            }
        }

        return $best !== null && $best['score'] >= $threshold ? $best : null;
    }

    /**
     * ¿Aparece la aguja dentro del pajar, tolerando ruido de escaneo?
     *
     * Primero busca la cita normalizada tal cual; si no, desliza una ventana del mismo
     * largo (en palabras) por el texto y acepta la mejor si su similitud supera el umbral.
     *
     * @return float similitud de la mejor coincidencia (1.0 = exacta)
     */
    public static function containment(string $needle, string $haystack): float
    {
        $needle = self::normalize($needle);
        $haystack = self::normalize($haystack);

        if ($needle === '' || $haystack === '') {
            return 0.0;
        }

        if (str_contains($haystack, $needle)) {
            return 1.0;
        }

        $needleWords = explode(' ', $needle);
        $words = explode(' ', $haystack);
        $size = count($needleWords);

        if ($size > count($words)) {
            return self::similarity($needle, $haystack);
        }

        $best = 0.0;
        $first = $needleWords[0];

        for ($i = 0, $limit = count($words) - $size; $i <= $limit; $i++) {
            // Poda barata: la ventana debe compartir alguna palabra con el inicio o el final de la cita.
            $window = array_slice($words, $i, $size + 1);
            if (! in_array($first, $window, true) && ! in_array(end($needleWords), $window, true) && levenshtein($first, $words[$i]) > 2) {
                continue;
            }

            foreach ([$size - 1, $size, $size + 1] as $length) {
                if ($length < 1 || $i + $length > count($words)) {
                    continue;
                }

                $candidate = implode(' ', array_slice($words, $i, $length));
                $score = self::similarity($needle, $candidate);

                if ($score > $best) {
                    $best = $score;
                }
            }

            if ($best >= 0.99) {
                break;
            }
        }

        return $best;
    }
}
