<?php

namespace App\Support\Tools;

/**
 * Convierte el texto de la IA (con marcas ligeras de markdown) en HTML seguro
 * para los PDF: primero escapa TODO y después reconoce negritas y saltos de
 * línea. Nunca deja pasar etiquetas del texto original.
 */
final class TextFormatter
{
    public static function toHtml(string $text): string
    {
        $escaped = e(str_replace("\r\n", "\n", $text));
        $escaped = preg_replace('/^#{1,6}\s+(.+)$/m', '<strong>$1</strong>', $escaped);
        $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);

        return nl2br($escaped, false);
    }

    /** Texto plano sin marcas, para copiar o buscar. */
    public static function plain(string $text): string
    {
        return trim(preg_replace(['/\*\*(.+?)\*\*/s', '/^#{1,6}\s+/m'], ['$1', ''], $text));
    }
}
