<?php

namespace App\Services\Tenders;

use App\Models\Tender;

/**
 * Lee el texto OCR de la ficha de expediente de HonduCompras.
 *
 * Busca las etiquetas conocidas al inicio de cada línea con similitud aproximada (el OCR
 * suele anteponer «[», «l» o «|» y cambiar letras), toma el resto de la línea como valor y
 * valida cada valor según su tipo. Cada campo sale con un estado:
 *
 *   read       leído y con forma válida
 *   doubtful   leído pero con dudas (corregido, formato raro, valor fuera de catálogo)
 *   missing    no se encontró la etiqueta o el valor
 *
 * Uso: (new HonduComprasCaptureParser)->parse($ocrText)
 *      // ['fields' => ['reference' => ['value' => 'CPR-SIT-025-2022', 'status' => 'doubtful', 'raw' => …, 'note' => …], …], 'useful' => true]
 */
class HonduComprasCaptureParser
{
    public const READ = 'read';

    public const DOUBTFUL = 'doubtful';

    public const MISSING = 'missing';

    /** Etiqueta de la ficha => campo de la licitación. El orden es el de la ficha. */
    private const LABELS = [
        'Expediente' => 'reference',
        'Entidad' => 'entity',
        'Unidad de Compra' => 'purchasing_unit',
        'Objeto' => 'title',
        'Fecha de Inicio' => 'started_at',
        'Fecha Recepción Ofertas' => 'presentation_deadline',
        'Fecha Cierre Aclaratorias' => 'clarification_deadline',
        'Tipo Fuente' => 'funding_source_type',
        'Fuente' => 'funding_source',
        'Modalidad' => 'purchase_type',
        'Etapa' => 'honducompras_stage',
        'Tipo Adquisición' => 'acquisition_type',
        'Lugar Recepción Ofertas' => 'offer_reception_place',
        'Valor Pliegos' => 'bid_document_fee',
        'Contacto' => 'contact',
    ];

    /** Campos cuyo valor puede ocupar varias líneas (también las anteriores a la etiqueta). */
    private const MULTILINE = ['title', 'contact'];

    /** Fin de la ficha: lo que sigue (pestañas, documentos) no se lee. */
    private const END_MARKERS = ['Detalle de la Compra', 'Productos o Servicios Solicitados'];

    /** Prefijos de expediente habituales: sirven para reponer el guion que el OCR se come. */
    private const REFERENCE_PREFIXES = ['LPN', 'LPI', 'LPR', 'CPR', 'CPU', 'CDP', 'CD', 'CM', 'SDC', 'SI', 'CP', 'LP'];

    private const VOCABULARY = [
        'funding_source_type' => ['Recursos Nacionales', 'Recursos Externos', 'Fondos Propios', 'Préstamo', 'Donación', 'Fideicomiso'],
        'funding_source' => ['Entidad', 'Tesoro Nacional', 'Préstamo', 'Donación', 'Fondos Propios', 'Recursos Propios', 'Crédito Externo'],
        'purchase_type' => Tender::MODALITIES,
        'honducompras_stage' => ['Publicado', 'En Recepción de Ofertas', 'Recepción de Ofertas', 'Apertura de Ofertas', 'En Evaluación', 'Evaluación', 'Adjudicado', 'Desierto', 'Fracasado', 'Cancelado', 'Suspendido', 'Contratado', 'Terminado'],
        'acquisition_type' => ['Bienes', 'Servicios', 'Consultoria', 'Obras', 'Servicios de Consultoria', 'Obra Pública', 'Suministro'],
    ];

    /**
     * @return array{fields: array<string, array{value: mixed, status: string, raw: ?string, note: ?string}>, useful: bool, lines: int}
     */
    public function parse(string $text): array
    {
        $lines = $this->lines($text);
        $found = $this->locateLabels($lines);
        $fields = [];

        foreach (self::LABELS as $label => $field) {
            $fields[$field] = $this->extract($field, $found, $lines);
        }

        $fields = $this->splitContact($fields);

        $read = count(array_filter($fields, fn ($field) => $field['status'] !== self::MISSING));
        $critical = array_filter(['reference', 'presentation_deadline', 'clarification_deadline', 'entity'], fn ($key) => $fields[$key]['status'] !== self::MISSING);

        return [
            'fields' => $fields,
            // «Útil» = al menos un dato clave y varios campos: si no, se ofrece la carga manual.
            'useful' => count($critical) >= 1 && $read >= 3,
            'lines' => count($lines),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $text): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));

            if ($line === '') {
                continue;
            }

            if ($this->isEndMarker($line)) {
                break;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function isEndMarker(string $line): bool
    {
        foreach (self::END_MARKERS as $marker) {
            $words = count(explode(' ', $marker));
            $head = implode(' ', array_slice(explode(' ', TextMatcher::normalize($line)), 0, $words));

            if (TextMatcher::similarity($head, $marker) >= 0.8) {
                return true;
            }
        }

        return false;
    }

    /**
     * Para cada línea, la etiqueta con la que empieza (si alguna), con su puntaje y el resto.
     *
     * @param  array<int, string>  $lines
     * @return array<string, array{line: int, rest: string, score: float}>
     */
    private function locateLabels(array $lines): array
    {
        $found = [];

        foreach ($lines as $index => $line) {
            $match = $this->labelAtStart($line);

            if ($match === null) {
                continue;
            }

            $field = self::LABELS[$match['label']];

            // Si una etiqueta aparece dos veces, gana la de mejor puntaje.
            if (! isset($found[$field]) || $found[$field]['score'] < $match['score']) {
                $found[$field] = ['line' => $index, 'rest' => $match['rest'], 'score' => $match['score']];
            }
        }

        return $found;
    }

    /**
     * @return array{label: string, rest: string, score: float}|null
     */
    private function labelAtStart(string $line): ?array
    {
        // Hasta dos caracteres de ruido antes de la etiqueta: «[», «|», «l», «I», «(».
        $candidates = [$line];
        if (preg_match('/^[\[\]|lI1(\'"“”‘’!.,:;\-_ ]{1,2}(.+)$/u', $line, $noise)) {
            $candidates[] = $noise[1];
        }

        $best = null;

        foreach ($candidates as $offset => $candidate) {
            $words = preg_split('/\s+/u', $candidate);

            foreach (array_keys(self::LABELS) as $label) {
                $labelWords = count(explode(' ', $label));

                // La etiqueta leída puede tener una palabra más o menos (OCR junta o separa).
                foreach ([$labelWords, $labelWords + 1, $labelWords - 1] as $take) {
                    if ($take < 1 || $take > count($words)) {
                        continue;
                    }

                    $head = implode(' ', array_slice($words, 0, $take));
                    $score = TextMatcher::similarity($head, $label) - ($offset * 0.02);

                    if ($score >= 0.8 && ($best === null || $score > $best['score'] || ($score === $best['score'] && mb_strlen($label) > mb_strlen($best['label'])))) {
                        $best = ['label' => $label, 'rest' => trim(implode(' ', array_slice($words, $take))), 'score' => $score];
                    }
                }
            }
        }

        // «Fuente» no debe ganarle a «Tipo Fuente» ni «Fecha …» confundirse entre sí:
        // la comparación ya prefiere la etiqueta más larga con el mismo puntaje.
        return $best;
    }

    /**
     * @param  array<string, array{line: int, rest: string, score: float}>  $found
     * @param  array<int, string>  $lines
     * @return array{value: mixed, status: string, raw: ?string, note: ?string}
     */
    private function extract(string $field, array $found, array $lines): array
    {
        if (! isset($found[$field])) {
            return $this->missing();
        }

        $raw = $found[$field]['rest'];

        if (in_array($field, self::MULTILINE, true)) {
            $raw = $this->multilineValue($field, $found, $lines);
        }

        $raw = trim($raw, " \t\"“”'‘’|[]");

        if ($raw === '') {
            return $this->missing();
        }

        $result = match ($field) {
            'reference' => $this->reference($raw),
            'started_at', 'presentation_deadline', 'clarification_deadline' => $this->dateTime($raw),
            'bid_document_fee' => $this->money($raw),
            'funding_source_type', 'funding_source', 'purchase_type', 'honducompras_stage', 'acquisition_type' => $this->fromVocabulary($field, $raw),
            default => ['value' => $raw, 'status' => self::READ, 'note' => null],
        };

        // Etiqueta reconocida con poca seguridad: el valor queda como dudoso.
        if ($result['status'] === self::READ && $found[$field]['score'] < 0.86) {
            $result['status'] = self::DOUBTFUL;
            $result['note'] ??= 'La etiqueta se leyó con errores; revisa el valor.';
        }

        return $result + ['raw' => $raw];
    }

    /**
     * Valor de varias líneas: desde la línea siguiente a la etiqueta anterior hasta la línea
     * previa a la siguiente etiqueta (el OCR a veces deja la etiqueta en medio del texto).
     *
     * @param  array<string, array{line: int, rest: string, score: float}>  $found
     * @param  array<int, string>  $lines
     */
    private function multilineValue(string $field, array $found, array $lines): string
    {
        $own = $found[$field]['line'];
        $previous = -1;
        $next = count($lines);

        foreach ($found as $other => $position) {
            if ($other === $field) {
                continue;
            }

            if ($position['line'] < $own) {
                $previous = max($previous, $position['line']);
            } elseif ($position['line'] > $own) {
                $next = min($next, $position['line']);
            }
        }

        $parts = [];
        for ($i = $previous + 1; $i < $next; $i++) {
            $parts[] = $i === $own ? $found[$field]['rest'] : $lines[$i];
        }

        $separator = $field === 'contact' ? "\n" : ' ';

        return implode($separator, array_filter(array_map(fn ($part) => trim($part, " \"“”'‘’"), $parts), fn ($part) => $part !== ''));
    }

    /**
     * Expediente con forma «PREFIJO-UNIDAD-NNN-AAAA». Si al OCR le falta un guion entre dos
     * bloques de letras y el inicio es un prefijo conocido, se repone y se marca como dudoso.
     *
     * @return array{value: string, status: string, note: ?string}
     */
    private function reference(string $raw): array
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', $raw));
        $value = (string) preg_replace('/[^A-Z0-9\-\/]/', '', $value);

        if (preg_match('/^[A-Z]{2,5}(-[A-Z0-9]{2,})+-\d{4}$/', $value) && substr_count($value, '-') >= 3) {
            return ['value' => $value, 'status' => self::READ, 'note' => null];
        }

        if (preg_match('/^([A-Z]{4,12})-(.+)$/', $value, $parts)) {
            foreach (self::REFERENCE_PREFIXES as $prefix) {
                $rest = substr($parts[1], strlen($prefix));

                if (str_starts_with($parts[1], $prefix) && strlen($rest) >= 2) {
                    return [
                        'value' => "{$prefix}-{$rest}-{$parts[2]}",
                        'status' => self::DOUBTFUL,
                        'note' => "Se leyó «{$value}»; se agregó el guion después de {$prefix}. Confírmalo con la captura.",
                    ];
                }
            }
        }

        return ['value' => $value, 'status' => self::DOUBTFUL, 'note' => 'El expediente no tiene la forma habitual; revísalo con la captura.'];
    }

    /**
     * @return array{value: ?string, status: string, note: ?string}
     */
    private function dateTime(string $raw): array
    {
        $parsed = SpanishDates::numeric($raw);

        if ($parsed === null) {
            return ['value' => null, 'status' => self::MISSING, 'note' => 'No se pudo interpretar la fecha.'];
        }

        return $parsed['meridiem']
            ? ['value' => $parsed['value'], 'status' => self::READ, 'note' => null]
            : ['value' => $parsed['value'], 'status' => self::DOUBTFUL, 'note' => 'No se leyó a. m./p. m.; confirma la hora.'];
    }

    /**
     * «Lps. 1,500.00» (el OCR lo lee como «Los.», «Lp5.», «L.»).
     *
     * @return array{value: ?float, status: string, note: ?string}
     */
    private function money(string $raw): array
    {
        if (! preg_match('/(\d[\d,]*(?:\.\d{1,2})?)/', $raw, $match)) {
            return ['value' => null, 'status' => self::MISSING, 'note' => 'No se encontró el monto.'];
        }

        $value = (float) str_replace(',', '', $match[1]);
        $prefix = trim(substr($raw, 0, (int) strpos($raw, $match[1])));
        $clean = $prefix === '' || preg_match('/^(lps?|l|hnl)\.?$/i', $prefix) || TextMatcher::similarity($prefix, 'Lps') >= 0.6;

        return ['value' => $value, 'status' => $clean ? self::READ : self::DOUBTFUL, 'note' => $clean ? null : 'Revisa la moneda del monto.'];
    }

    /**
     * @return array{value: string, status: string, note: ?string}
     */
    private function fromVocabulary(string $field, string $raw): array
    {
        $exact = TextMatcher::closest($raw, self::VOCABULARY[$field], 0.97);
        if ($exact !== null) {
            return ['value' => $exact['value'], 'status' => self::READ, 'note' => null];
        }

        $close = TextMatcher::closest($raw, self::VOCABULARY[$field], 0.8);
        if ($close !== null) {
            return ['value' => $close['value'], 'status' => self::DOUBTFUL, 'note' => "Se leyó «{$raw}» y se corrigió a «{$close['value']}»."];
        }

        // Valor fuera de la lista conocida: se respeta tal cual (HonduCompras puede tener otros).
        return ['value' => $raw, 'status' => self::READ, 'note' => null];
    }

    /**
     * El contacto trae nombre, teléfono y correo en líneas distintas.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function splitContact(array $fields): array
    {
        $contact = $fields['contact'];
        unset($fields['contact']);

        $name = $phone = $email = null;

        foreach (preg_split('/\n/', (string) ($contact['raw'] ?? '')) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/\S+@\S+\.\S+/', $line, $match) || preg_match('/\S+\.(?:gob|com|org|net|edu)\.\S+/i', $line, $match)) {
                $email = $match[0];
            } elseif (preg_match('/\d{4}[\s\-]?\d{4}/', $line)) {
                $phone = $line;
            } else {
                $name = $name === null ? $line : "{$name} {$line}";
            }
        }

        $fields['contact_name'] = $name !== null ? ['value' => $name, 'status' => self::READ, 'raw' => $name, 'note' => null] : $this->missing();
        $fields['contact_phone'] = $phone !== null ? ['value' => $phone, 'status' => self::READ, 'raw' => $phone, 'note' => null] : $this->missing();

        if ($email === null) {
            $fields['contact_email'] = $this->missing();
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fields['contact_email'] = ['value' => strtolower($email), 'status' => self::READ, 'raw' => $email, 'note' => null];
        } else {
            $fields['contact_email'] = ['value' => $email, 'status' => self::DOUBTFUL, 'raw' => $email, 'note' => 'El correo no se leyó bien; corrígelo antes de confirmar.'];
        }

        return $fields;
    }

    /**
     * @return array{value: null, status: string, raw: null, note: null}
     */
    private function missing(): array
    {
        return ['value' => null, 'status' => self::MISSING, 'raw' => null, 'note' => null];
    }
}
