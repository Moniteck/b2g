<?php

namespace App\Http\Controllers\Shell;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Búsqueda global de la paleta ⌘K: `GET /buscar?q=`.
 *
 * Busca en la empresa activa (el scope `BelongsToCompany` ya filtra) licitaciones, oportunidades,
 * cotizaciones, clientes, contratos y documentos, y en los catálogos compartidos (instituciones y
 * contactos). Cada tipo respeta su política `viewAny`, trae como mucho `LIMIT` resultados con las
 * columnas justas y un `LIKE` acotado (sin comodín inicial para los códigos), así responde en
 * pocos milisegundos con los índices `company_id,…` existentes.
 *
 * Respuesta: { query, groups: [{ type, label, items: [{ id, title, subtitle, href, icon }] }] }
 */
class SearchController extends Controller
{
    public const LIMIT = 5;

    public const MIN_LENGTH = 2;

    public function __invoke(Request $request): JsonResponse
    {
        $term = Str::of((string) $request->query('q', ''))->squish()->limit(80, '')->value();

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return response()->json(['query' => $term, 'groups' => []]);
        }

        $contains = '%'.addcslashes($term, '%_\\').'%';
        $prefix = addcslashes($term, '%_\\').'%';
        $user = $request->user();
        $groups = [];

        foreach ($this->sources($contains, $prefix) as $type => $source) {
            if (! $user->can('viewAny', $source['model'])) {
                continue;
            }

            $items = $source['query']()->limit(self::LIMIT)->get()->map($source['map'])->values()->all();

            if ($items !== []) {
                $groups[] = ['type' => $type, 'label' => $source['label'], 'items' => $items];
            }
        }

        return response()->json(['query' => $term, 'groups' => $groups]);
    }

    /**
     * @return array<string, array{model: class-string, label: string, query: callable, map: callable}>
     */
    private function sources(string $contains, string $prefix): array
    {
        return [
            'tenders' => [
                'model' => Tender::class,
                'label' => 'Licitaciones',
                'query' => fn () => Tender::query()
                    ->select(['id', 'reference', 'title', 'status', 'presentation_deadline'])
                    ->where(fn (Builder $query) => $query->where('reference', 'like', $contains)->orWhere('title', 'like', $contains))
                    ->orderByDesc('presentation_deadline'),
                'map' => fn (Tender $tender) => [
                    'id' => $tender->id,
                    'title' => (string) $tender->reference,
                    'subtitle' => Str::limit(Str::ucfirst(Str::lower((string) $tender->title)), 90),
                    'href' => "/licitaciones/{$tender->id}",
                    'icon' => 'gavel',
                ],
            ],
            'opportunities' => [
                'model' => Opportunity::class,
                'label' => 'Oportunidades',
                'query' => fn () => Opportunity::query()
                    ->select(['id', 'title', 'stage'])
                    ->where('title', 'like', $contains)
                    ->latest('id'),
                'map' => fn (Opportunity $opportunity) => [
                    'id' => $opportunity->id,
                    'title' => (string) $opportunity->title,
                    'subtitle' => 'Pipeline · '.Str::ucfirst((string) $opportunity->stage),
                    'href' => "/pipeline/{$opportunity->id}",
                    'icon' => 'view-column-outline',
                ],
            ],
            'quotes' => [
                'model' => Quote::class,
                'label' => 'Cotizaciones',
                'query' => fn () => Quote::query()
                    ->select(['quotes.id', 'quotes.number', 'quotes.status', 'quotes.institution_id'])
                    ->with('institution:id,name,acronym')
                    ->where(fn (Builder $query) => $query->where('quotes.number', 'like', $contains)
                        ->orWhereHas('institution', fn (Builder $institution) => $institution->where('name', 'like', $contains)->orWhere('acronym', 'like', $prefix)))
                    ->latest('quotes.id'),
                'map' => fn (Quote $quote) => [
                    'id' => $quote->id,
                    'title' => (string) $quote->number,
                    'subtitle' => trim(($quote->institution?->acronym ?: $quote->institution?->name ?: 'Sin institución').' · '.$quote->status, ' ·'),
                    'href' => '/cotizaciones?'.http_build_query(['search' => $quote->number, 'ver' => $quote->id]),
                    'icon' => 'file-document-outline',
                ],
            ],
            'clients' => [
                'model' => Client::class,
                'label' => 'Clientes',
                'query' => fn () => Client::query()
                    ->select(['id', 'name', 'tax_id'])
                    ->where(fn (Builder $query) => $query->where('name', 'like', $contains)->orWhere('tax_id', 'like', $prefix))
                    ->orderBy('name'),
                'map' => fn (Client $client) => [
                    'id' => $client->id,
                    'title' => (string) $client->name,
                    'subtitle' => $client->tax_id ? "RTN {$client->tax_id}" : 'Cliente',
                    'href' => "/clientes/{$client->id}",
                    'icon' => 'account-group-outline',
                ],
            ],
            'contracts' => [
                'model' => Contract::class,
                'label' => 'Contratos',
                'query' => fn () => Contract::query()
                    ->select(['id', 'number', 'name', 'status'])
                    ->where(fn (Builder $query) => $query->where('number', 'like', $contains)->orWhere('name', 'like', $contains))
                    ->latest('id'),
                'map' => fn (Contract $contract) => [
                    'id' => $contract->id,
                    'title' => (string) $contract->number,
                    'subtitle' => Str::limit((string) $contract->name, 90),
                    'href' => "/post-adjudicacion/{$contract->id}",
                    'icon' => 'file-sign',
                ],
            ],
            'documents' => [
                'model' => Document::class,
                'label' => 'Documentos',
                'query' => fn () => Document::query()
                    ->select(['id', 'name', 'reference_number', 'expires_at'])
                    ->where(fn (Builder $query) => $query->where('name', 'like', $contains)->orWhere('reference_number', 'like', $prefix))
                    ->orderBy('name'),
                'map' => fn (Document $document) => [
                    'id' => $document->id,
                    'title' => (string) $document->name,
                    'subtitle' => $document->reference_number ? "Ref. {$document->reference_number}" : 'Directorio institucional',
                    'href' => '/directorio?'.http_build_query(['search' => $document->name]),
                    'icon' => 'folder-account-outline',
                ],
            ],
            'institutions' => [
                'model' => Institution::class,
                'label' => 'Instituciones',
                'query' => fn () => Institution::query()
                    ->select(['id', 'name', 'acronym'])
                    ->where(fn (Builder $query) => $query->where('name', 'like', $contains)->orWhere('acronym', 'like', $prefix))
                    ->orderBy('name'),
                'map' => fn (Institution $institution) => [
                    'id' => $institution->id,
                    'title' => (string) ($institution->acronym ?: $institution->name),
                    'subtitle' => $institution->acronym ? (string) $institution->name : 'Institución',
                    'href' => "/instituciones/{$institution->id}",
                    'icon' => 'bank-outline',
                ],
            ],
            'contacts' => [
                'model' => Contact::class,
                'label' => 'Contactos',
                'query' => fn () => Contact::query()
                    ->select(['contacts.id', 'contacts.name', 'contacts.position', 'contacts.institution_id'])
                    ->with('institution:id,name,acronym')
                    ->where(fn (Builder $query) => $query->where('contacts.name', 'like', $contains)->orWhere('contacts.email', 'like', $prefix))
                    ->orderBy('contacts.name'),
                'map' => fn (Contact $contact) => [
                    'id' => $contact->id,
                    'title' => (string) $contact->name,
                    'subtitle' => collect([$contact->position, $contact->institution?->acronym ?: $contact->institution?->name])->filter()->implode(' · ') ?: 'Contacto',
                    'href' => '/contactos?'.http_build_query(['search' => $contact->name]),
                    'icon' => 'card-account-phone-outline',
                ],
            ],
        ];
    }
}
