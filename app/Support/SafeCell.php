<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Celdas de exporte a prueba de inyección de fórmulas (CSV/XLSX).
 *
 * Un texto que viene de los datos (título de una licitación, nombre de un cliente, lectura de
 * OCR o de un cliente MCP) nunca debe llegar a Excel como fórmula:
 *
 * - XLSX: todo texto se guarda con tipo cadena explícito; `fromArray()`/`setCellValue()`
 *   convertirían en fórmula cualquier texto que empiece con «=».
 * - CSV: se antepone un apóstrofo a la celda que empieza con `= + - @`, tabulador o retorno
 *   (recomendación de OWASP), salvo que sea un número.
 *
 *   SafeCell::row($sheet, [$tender->reference, $tender->title, 1500.5], 'A2');
 *   fputcsv($out, SafeCell::csvRow([$client->name, $client->email]));
 */
final class SafeCell
{
    /** Caracteres con los que una hoja de cálculo interpreta la celda como fórmula. */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function csv(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], self::DANGEROUS_PREFIXES, true) ? "'".$value : $value;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int, mixed>
     */
    public static function csvRow(array $values): array
    {
        return array_map(self::csv(...), array_values($values));
    }

    /** Escribe un valor en la celda: los números como número y todo texto como cadena literal. */
    public static function set(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        if (is_string($value) && ! is_numeric($value)) {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);

            return;
        }

        $sheet->setCellValue($coordinate, $value);
    }

    /**
     * Reemplazo seguro de `$sheet->fromArray($values, null, $start)` para una fila.
     *
     * @param  array<int|string, mixed>  $values
     */
    public static function row(Worksheet $sheet, array $values, string $start): void
    {
        [$column, $row] = Coordinate::coordinateFromString($start);
        $index = Coordinate::columnIndexFromString($column);

        foreach (array_values($values) as $offset => $value) {
            if ($value === null) {
                continue;
            }

            self::set($sheet, Coordinate::stringFromColumnIndex($index + $offset).$row, $value);
        }
    }
}
