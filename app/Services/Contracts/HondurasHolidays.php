<?php

namespace App\Services\Contracts;

/**
 * Feriados nacionales de Honduras que se siembran en cada empresa.
 *
 * Fijos por el Código del Trabajo (art. 339): 1 de enero, 14 de abril, 1 de mayo, 15 de
 * septiembre y 25 de diciembre, más Jueves, Viernes y Sábado Santo. Los feriados cívicos del 3,
 * 12 y 21 de octubre se gozan juntos como Semana Morazánica (Decreto 78-2015): el Gobierno fija
 * cada año los tres días. 2026 se tomó del anuncio de Gobernación (miércoles 7 a viernes 9 de
 * octubre); 2027 es una estimación con la misma regla (primer miércoles a viernes de octubre)
 * y queda marcada para revisión.
 */
final class HondurasHolidays
{
    /**
     * @return array<int, array{holiday_date: string, name: string, is_pending_review: bool}>
     */
    public static function defaults(): array
    {
        $rows = [];

        foreach ([2026, 2027] as $year) {
            foreach (self::forYear($year) as [$date, $name, $review]) {
                $rows[] = ['holiday_date' => $date, 'name' => $name, 'is_pending_review' => $review];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: bool}>
     */
    public static function forYear(int $year): array
    {
        $easter = self::easter($year);
        $morazan = [
            2026 => ['2026-10-07', '2026-10-08', '2026-10-09', false],
            2027 => ['2027-10-06', '2027-10-07', '2027-10-08', true],
        ][$year] ?? null;

        $list = [
            ["{$year}-01-01", 'Año Nuevo', false],
            [$easter->modify('-3 days')->format('Y-m-d'), 'Jueves Santo', false],
            [$easter->modify('-2 days')->format('Y-m-d'), 'Viernes Santo', false],
            [$easter->modify('-1 day')->format('Y-m-d'), 'Sábado Santo', false],
            ["{$year}-04-14", 'Día de las Américas', false],
            ["{$year}-05-01", 'Día del Trabajador', false],
            ["{$year}-09-15", 'Día de la Independencia', false],
        ];

        if ($morazan !== null) {
            $list[] = [$morazan[0], 'Semana Morazánica (Día de Morazán)', $morazan[3]];
            $list[] = [$morazan[1], 'Semana Morazánica (Día de la Raza)', $morazan[3]];
            $list[] = [$morazan[2], 'Semana Morazánica (Día de las Fuerzas Armadas)', $morazan[3]];
        }

        $list[] = ["{$year}-12-25", 'Navidad', false];

        return $list;
    }

    /** Domingo de Pascua (algoritmo gregoriano anónimo; no depende de la extensión `calendar`). */
    public static function easter(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
