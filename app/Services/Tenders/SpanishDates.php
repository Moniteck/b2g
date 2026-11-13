<?php

namespace App\Services\Tenders;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Fechas de HonduCompras y de pliegos, siempre en hora de Honduras.
 *
 *   SpanishDates::numeric('13/07/2022 06:21:00 p.m.')
 *       // ['value' => '2022-07-13 18:21:00', 'meridiem' => true]
 *   SpanishDates::prose('jueves diecisiete (/ 7) de septiembre del año dos mil veintiséis (2026)')
 *       // ['date' => '2026-09-17', 'time' => null]
 */
final class SpanishDates
{
    public const TIMEZONE = 'America/Tegucigalpa';

    private const MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
    ];

    /** Números de día escritos en letras (normalizados, sin tildes). */
    private const DAY_WORDS = [
        'treinta y uno' => 31, 'treinta' => 30, 'veintinueve' => 29, 'veintiocho' => 28,
        'veintisiete' => 27, 'veintiseis' => 26, 'veinticinco' => 25, 'veinticuatro' => 24,
        'veintitres' => 23, 'veintidos' => 22, 'veintiuno' => 21, 'veintiun' => 21, 'veinte' => 20,
        'diecinueve' => 19, 'dieciocho' => 18, 'diecisiete' => 17, 'dieciseis' => 16, 'quince' => 15,
        'catorce' => 14, 'trece' => 13, 'doce' => 12, 'once' => 11, 'diez' => 10, 'nueve' => 9,
        'ocho' => 8, 'siete' => 7, 'seis' => 6, 'cinco' => 5, 'cuatro' => 4, 'tres' => 3, 'dos' => 2,
        'primero' => 1, 'uno' => 1,
    ];

    /**
     * Fecha numérica «dd/mm/aaaa hh:mm[:ss] a.m./p.m.» (tolera «p. m.», «pm», puntos y guiones).
     *
     * @return array{value: string, meridiem: bool}|null
     */
    public static function numeric(string $text): ?array
    {
        $pattern = '/(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{4})(?:\s+(\d{1,2})\s*[:.]\s*(\d{2})(?:\s*[:.]\s*(\d{2}))?\s*([ap])?\s*\.?\s*(?:m\b\.?)?)?/i';

        if (! preg_match($pattern, $text, $match)) {
            return null;
        }

        [$day, $month, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $hour = isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 0;
        $minute = isset($match[5]) && $match[5] !== '' ? (int) $match[5] : 0;
        $second = isset($match[6]) && $match[6] !== '' ? (int) $match[6] : 0;
        $meridiem = strtolower($match[7] ?? '');

        if ($meridiem === 'p' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'a' && $hour === 12) {
            $hour = 0;
        }

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $value = CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, self::TIMEZONE);

        return [
            'value' => $value->format('Y-m-d H:i:s'),
            // Sin a.m./p.m. una hora como «04:00» es ambigua: el analizador la marca como dudosa.
            'meridiem' => $meridiem !== '' || ! isset($match[4]) || $match[4] === '' || $hour >= 13 || $hour === 0,
        ];
    }

    /**
     * Fecha en prosa de un pliego. Devuelve la primera que encuentra.
     *
     * @return array{date: string, time: ?string}|null
     */
    public static function prose(string $text): ?array
    {
        $normalized = TextMatcher::normalize($text);
        $months = implode('|', array_keys(self::MONTHS));

        if (! preg_match('/\b('.$months.')\b/', $normalized, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $month = self::MONTHS[$match[1][0]];
        $before = substr($normalized, max(0, $match[0][1] - 60), min(60, $match[0][1]));
        $after = substr($normalized, $match[0][1], 120);

        $day = self::dayFrom($before);
        $year = preg_match('/\b(20\d{2})\b/', $after, $years) ? (int) $years[1] : null;

        if ($day === null || $year === null || ! checkdate($month, $day, $year)) {
            return null;
        }

        return [
            'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
            'time' => self::time($text),
        ];
    }

    /** Hora «10:15 a.m.», «10:00 am», «4:00 p. m.» → «10:15», «10:00», «16:00». */
    public static function time(string $text): ?string
    {
        if (! preg_match('/\b(\d{1,2})\s*[:.]\s*(\d{2})\s*(?:([ap])\s*\.?\s*m\b\.?)?/i', $text, $match)) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];
        $meridiem = strtolower($match[3] ?? '');

        if ($meridiem === 'p' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'a' && $hour === 12) {
            $hour = 0;
        }

        return $hour <= 23 && $minute <= 59 ? sprintf('%02d:%02d', $hour, $minute) : null;
    }

    /** Fecha y hora ISO («2026-09-30T10:15», «2026-09-30 10:15:00», «2026-09-30») en hora de Honduras. */
    public static function iso(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?/', trim($value))) {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr(trim($value), 0, 19), self::TIMEZONE);
        } catch (Throwable) {
            return null;
        }
    }

    private static function dayFrom(string $before): ?int
    {
        // Se queda con lo que va después del último «el dia», «hasta el» o signo fuerte.
        $segment = preg_split('/\b(?:dia|el|hasta)\b/', $before);
        $segment = trim((string) end($segment));

        foreach (self::DAY_WORDS as $word => $number) {
            if (preg_match('/\b'.$word.'\b/', $segment)) {
                return $number;
            }
        }

        if (preg_match_all('/\b(\d{1,2})\b/', $segment, $numbers) && $numbers[1] !== []) {
            $day = (int) end($numbers[1]);

            return $day >= 1 && $day <= 31 ? $day : null;
        }

        return null;
    }
}
