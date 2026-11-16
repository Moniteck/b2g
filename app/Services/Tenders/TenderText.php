<?php

namespace App\Services\Tenders;

/**
 * Presentación del texto que llega de HonduCompras, que suele venir TODO EN MAYÚSCULAS.
 *
 *   TenderText::display('1. SUPERVISIÓN DEL BACHEO DE LA RUTA CA-7 (SIT)')
 *   // «1. Supervisión del bacheo de la ruta CA-7 (SIT)»
 *
 * Solo cambia lo que se muestra; el dato guardado queda como se leyó. Un texto con minúsculas
 * se respeta tal cual (alguien ya lo escribió bien).
 */
final class TenderText
{
    public static function display(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || ! self::shouting($text)) {
            return $text;
        }

        $words = explode(' ', mb_strtolower($text));
        $original = explode(' ', $text);
        $capitalize = true;

        foreach ($words as $index => $word) {
            $raw = $original[$index];

            // Códigos, rutas y siglas: «CA-7», «S156», «LPN-001-2026», «(SIT)», «II».
            if (self::isCode($raw)) {
                $words[$index] = $raw;
                $capitalize = false;

                continue;
            }

            if ($capitalize && preg_match('/\p{L}/u', $word)) {
                $words[$index] = self::ucfirst($word);
                $capitalize = false;
            }

            // Tras punto final o numeración («1.», «2.»), la siguiente palabra va con mayúscula.
            if (preg_match('/[.:;!?]$/u', $raw)) {
                $capitalize = true;
            }
        }

        return implode(' ', $words);
    }

    /** Más del 70 % de las letras en mayúscula (y al menos 8 letras). */
    public static function shouting(string $text): bool
    {
        $letters = preg_match_all('/\p{L}/u', $text);
        $upper = preg_match_all('/\p{Lu}/u', $text);

        return $letters >= 8 && $upper / $letters > 0.7;
    }

    private static function isCode(string $word): bool
    {
        $bare = trim($word, '.,;:()[]"«»');

        if ($bare === '') {
            return false;
        }

        return (bool) preg_match('/\d/u', $bare) && preg_match('/\p{L}/u', $bare)
            || preg_match('/^\(\p{Lu}{2,8}\)[.,;:]?$/u', $word)
            || preg_match('/^(?:[IVX]{2,4}|[A-Z]{2,5}-[A-Z0-9-]+)$/u', $bare);
    }

    private static function ucfirst(string $word): string
    {
        // Respeta signos de apertura: «"supervisión» → «"Supervisión».
        return (string) preg_replace_callback('/\p{L}/u', fn ($m) => mb_strtoupper($m[0]), $word, 1);
    }
}
