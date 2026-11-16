<?php

namespace App\Services\Tenders;

use App\Jobs\AnalyzeBidDocument;
use App\Models\ComplianceItem;
use App\Models\ComplianceMatrix;
use App\Models\DocumentExtraction;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use App\Services\Business\QuoteService;
use App\Services\Documents\DocumentService;
use App\Services\Documents\FileTypes;
use App\Services\System\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo del análisis de un pliego: subir, encolar, reintentar, revisar y confirmar.
 *
 *   $extraction = $service->start($tender, $file, $user);        // guarda el PDF y encola el análisis
 *   $rows = $service->proposal($extraction, $tender);            // filas de la pantalla de revisión
 *   $service->confirm($extraction, $tender, $fields, $lists, $user);
 *   $service->createMatrix($extraction, $tender, $user);         // idempotente
 *   $service->createQuote($extraction, $tender, $user, $indexes); // idempotente
 */
class BidReviewService
{
    /** Dato del pliego => [etiqueta, columna de la licitación, tipo]. */
    public const FIELD_MAP = [
        'process_number' => ['Número del proceso', 'reference', 'text'],
        'process_name' => ['Nombre del proceso', 'process_name', 'text'],
        'entity' => ['Entidad', null, 'text'],
        'object' => ['Objeto', 'title', 'text'],
        'clarification_deadline' => ['Cierre de aclaraciones', 'clarification_deadline', 'datetime'],
        'presentation_deadline' => ['Presentación de ofertas', 'presentation_deadline', 'datetime'],
        'presentation_place' => ['Lugar de presentación', 'offer_reception_place', 'text'],
        'opening_at' => ['Apertura de ofertas', 'opening_at', 'datetime'],
        'offer_validity_days' => ['Vigencia de la oferta (días)', 'offer_validity_days', 'integer'],
        'delivery_term' => ['Plazo de entrega', 'delivery_terms', 'text'],
        'contact' => ['Contacto para consultas', 'contact_name', 'text'],
    ];

    /** Columnas que identifican la licitación: con valor previo no se proponen por defecto. */
    private const IDENTITY = ['reference', 'title', 'process_name'];

    private const MATRIX_NAME = 'Matriz del pliego';

    public function __construct(
        protected DocumentService $documents,
        protected QuoteService $quotes,
        protected AuditLogger $audit,
    ) {}

    public function start(Tender $tender, UploadedFile $file, User $user): DocumentExtraction
    {
        $document = $this->documents->create([
            'name' => 'Pliego de condiciones '.$tender->reference,
            'kind' => 'proceso',
            'category' => 'Pliego',
            'company_id' => $tender->company_id,
            'institution_id' => $tender->institution_id,
        ], $file, $user, $tender, ['context' => 'pliego', 'category' => 'Pliego', 'allowed' => FileTypes::PDF_ONLY]);

        $extraction = DocumentExtraction::create([
            'company_id' => $tender->company_id,
            'tender_id' => $tender->id,
            'document_version_id' => $document->versions()->max('id'),
            'kind' => DocumentExtraction::KIND_BID,
            'status' => DocumentExtraction::QUEUED,
            'method' => 'pdftotext',
        ]);

        AnalyzeBidDocument::dispatch($extraction->id)->afterCommit();

        $this->audit->record('licitacion.pliego_subido', $tender, ['documento' => $document->id, 'analisis' => $extraction->id], $user);

        return $extraction;
    }

    public function retry(DocumentExtraction $extraction, User $user): void
    {
        DB::transaction(function () use ($extraction) {
            $locked = DocumentExtraction::query()->whereKey($extraction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DocumentExtraction::FAILED) {
                throw ValidationException::withMessages(['extraction' => 'Este análisis no está fallido: no hace falta reintentarlo.']);
            }

            $locked->forceFill(['status' => DocumentExtraction::QUEUED, 'error' => null, 'finished_at' => null])->save();
        });

        AnalyzeBidDocument::dispatch($extraction->id);

        $this->audit->record('licitacion.pliego_reintento', $extraction->tender, ['analisis' => $extraction->id], $user);
    }

    /**
     * Filas para la revisión: dato del pliego, valor actual de la licitación y si conviene aplicarlo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function proposal(DocumentExtraction $extraction, Tender $tender): array
    {
        $fields = $extraction->extracted['fields'] ?? [];
        $rows = [];

        foreach (self::FIELD_MAP as $key => [$label, $column, $type]) {
            $proposal = $fields[$key] ?? null;
            $current = $column ? $this->currentValue($tender, $column, $type) : null;
            $value = $proposal['value'] ?? null;
            $differs = $proposal !== null && $current !== null && $this->differs($current, $value, $type);
            $verified = ($proposal['status'] ?? null) === BidEvidenceVerifier::VERIFIED;

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'column' => $column,
                'type' => $type,
                'found' => $proposal !== null,
                'value' => $value,
                'status' => $proposal['status'] ?? null,
                'quote' => $proposal['quote'] ?? null,
                'page' => $proposal['page'] ?? null,
                'note' => $proposal['note'] ?? null,
                'alternatives' => $proposal['alternatives'] ?? [],
                'current' => $current,
                'conflict' => $differs,
                // Nunca se propone aplicar lo que no tiene evidencia ni lo que pisa la identidad del proceso.
                'apply' => $column !== null && $verified && ($current === null || ($differs && ! in_array($column, self::IDENTITY, true) && $type !== 'datetime')),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{apply?: bool, value?: mixed}>  $fields
     * @param  array{documents?: bool, criteria?: bool}  $lists
     */
    public function confirm(DocumentExtraction $extraction, Tender $tender, array $fields, array $lists, User $user): bool
    {
        return DB::transaction(function () use ($extraction, $tender, $fields, $lists, $user) {
            $locked = DocumentExtraction::query()->whereKey($extraction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === DocumentExtraction::CONFIRMED) {
                return false;
            }

            if ($locked->status !== DocumentExtraction::REVIEW) {
                throw ValidationException::withMessages(['extraction' => 'El análisis aún no está listo para revisar.']);
            }

            $tender = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();
            $updates = [];
            $applied = [];

            foreach (self::FIELD_MAP as $key => [, $column, $type]) {
                if ($column === null || empty($fields[$key]['apply'])) {
                    continue;
                }

                $value = $fields[$key]['value'] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                $updates[$column] = match ($type) {
                    'datetime' => TenderAttributes::dateTime($value),
                    'integer' => (int) $value,
                    default => Str::limit(trim((string) $value), $column === 'title' ? 1000 : 255, ''),
                };
                $applied[] = $key;
            }

            $extracted = $locked->extracted ?? [];

            if (! empty($lists['documents']) && ! empty($extracted['lists']['required_documents'])) {
                $updates['documents'] = array_values(array_unique(array_map(
                    fn ($item) => Str::limit((string) $item['name'], 255, ''),
                    $extracted['lists']['required_documents'],
                )));
                $applied[] = 'required_documents';
            }

            if (! empty($lists['criteria']) && ! empty($extracted['lists']['evaluation_criteria'])) {
                $updates['requirements'] = Str::limit(implode("\n", array_map(fn ($item) => '- '.$item['criterion'], $extracted['lists']['evaluation_criteria'])), 10000, '');
                $applied[] = 'evaluation_criteria';
            }

            if (isset($updates['reference']) && Tender::query()->where('reference', $updates['reference'])->whereKeyNot($tender->id)->exists()) {
                throw ValidationException::withMessages(['fields.process_number.value' => 'Ya existe otra licitación con ese número de proceso.']);
            }

            if (isset($updates['presentation_deadline'])) {
                $updates['closing_date'] = substr($updates['presentation_deadline'], 0, 10);
            }

            // Se guarda con Eloquent para que los observadores (calendario) vean el cambio.
            $tender->fill($updates)->save();

            $extracted['applied'] = $applied;
            $locked->forceFill([
                'status' => DocumentExtraction::CONFIRMED,
                'extracted' => $extracted,
                'reviewed_at' => now(),
                'reviewed_by' => $user->id,
            ])->save();

            $this->audit->record('licitacion.pliego_confirmado', $tender, ['analisis' => $locked->id, 'aplicados' => $applied], $user);

            return true;
        });
    }

    /**
     * Matriz documental con los documentos exigidos y las garantías. Si ya existe, agrega
     * solo lo que falta (dos clics no duplican ítems).
     */
    public function createMatrix(DocumentExtraction $extraction, Tender $tender, User $user): ComplianceMatrix
    {
        return DB::transaction(function () use ($extraction, $tender, $user) {
            $locked = DocumentExtraction::query()->whereKey($extraction->id)->lockForUpdate()->firstOrFail();
            $lists = $locked->extracted['lists'] ?? [];

            $requirements = array_merge(
                array_map(fn ($item) => (string) $item['name'], $lists['required_documents'] ?? []),
                array_map(fn ($item) => trim('Garantía: '.$item['type'].($item['percentage'] ? " ({$item['percentage']})" : '').($item['validity'] ? ", vigencia {$item['validity']}" : '')), $lists['guarantees'] ?? []),
            );

            if ($requirements === []) {
                throw ValidationException::withMessages(['matrix' => 'El análisis no encontró documentos ni garantías para armar la matriz.']);
            }

            $matrix = ComplianceMatrix::query()->firstOrCreate(
                ['tender_id' => $tender->id, 'name' => self::MATRIX_NAME],
                ['company_id' => $tender->company_id],
            );

            $existing = $matrix->items()->pluck('requirement')->map(fn ($r) => TextMatcher::normalize($r))->all();

            foreach ($requirements as $requirement) {
                $requirement = Str::limit(trim($requirement), 255, '');

                if ($requirement !== '' && ! in_array(TextMatcher::normalize($requirement), $existing, true)) {
                    ComplianceItem::create(['compliance_matrix_id' => $matrix->id, 'requirement' => $requirement, 'completed' => false]);
                    $existing[] = TextMatcher::normalize($requirement);
                }
            }

            $locked->forceFill(['evidence' => array_merge($locked->evidence ?? [], ['matrix_id' => $matrix->id])])->save();
            $this->audit->record('licitacion.matriz_desde_pliego', $tender, ['matriz' => $matrix->id], $user);

            return $matrix;
        });
    }

    /**
     * Cotización borrador con la lista de productos del pliego (precio en cero para completar).
     *
     * @param  array<int, int>|null  $indexes  productos elegidos (null = todos)
     */
    public function createQuote(DocumentExtraction $extraction, Tender $tender, User $user, ?array $indexes = null): Quote
    {
        return DB::transaction(function () use ($extraction, $tender, $user, $indexes) {
            $locked = DocumentExtraction::query()->whereKey($extraction->id)->lockForUpdate()->firstOrFail();

            if (($quoteId = $locked->evidence['quote_id'] ?? null) && ($quote = Quote::query()->find($quoteId))) {
                return $quote;
            }

            $items = $locked->extracted['lists']['items'] ?? [];

            if ($indexes !== null) {
                $items = array_values(array_intersect_key($items, array_flip($indexes)));
            }

            if ($items === []) {
                throw ValidationException::withMessages(['quote' => 'No hay productos del pliego para la cotización.']);
            }

            $quote = $this->quotes->create($tender->company_id, $user->id, [
                'tender_id' => $tender->id,
                'institution_id' => $tender->institution_id,
                'opportunity_id' => $tender->opportunity?->id,
                'purchase_type' => $tender->purchase_type ?: 'Licitación',
                'validity_days' => $tender->offer_validity_days ?: 30,
                'notes' => 'Borrador creado desde el análisis del pliego de '.$tender->reference.'. Completa los precios.',
                'items' => array_map(fn ($item) => [
                    'description' => Str::limit((string) $item['description'], 1000, ''),
                    'quantity' => $this->quantity($item['quantity'] ?? 1),
                    'unit' => Str::limit((string) ($item['unit'] ?: 'Unidad'), 50, ''),
                    'unit_price' => 0,
                ], $items),
            ]);

            $locked->forceFill(['evidence' => array_merge($locked->evidence ?? [], ['quote_id' => $quote->id])])->save();

            return $quote;
        });
    }

    private function quantity(mixed $value): float
    {
        if (is_numeric($value)) {
            return max((float) $value, 1);
        }

        return preg_match('/\d[\d,]*(\.\d+)?/', (string) $value, $match) ? max((float) str_replace(',', '', $match[0]), 1) : 1;
    }

    private function currentValue(Tender $tender, string $column, string $type): mixed
    {
        $value = $tender->getAttribute($column);

        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'datetime' => $value->format('Y-m-d\TH:i'),
            'integer' => (int) $value,
            default => (string) $value,
        };
    }

    private function differs(mixed $current, mixed $proposed, string $type): bool
    {
        if ($type === 'datetime') {
            // Una fecha sin hora del pliego coincide con la actual si es el mismo día.
            $length = min(strlen((string) $current), strlen((string) $proposed)) <= 10 ? 10 : 16;

            return substr((string) $current, 0, $length) !== substr((string) $proposed, 0, $length);
        }

        if ($type === 'integer') {
            return (int) $current !== (int) $proposed;
        }

        return TextMatcher::normalize((string) $current) !== TextMatcher::normalize((string) $proposed);
    }
}
