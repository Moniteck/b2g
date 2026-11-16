<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Quote;
use App\Services\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use App\Support\SafeCell;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Base de clientes de la empresa activa. No hay alta manual: los clientes nacen
 * cuando una oferta se registra como ganada (`AdjudicationService`).
 */
class ClientController extends Controller
{
    public const SORTS = ['name', 'accumulated_amount', 'last_purchase_at'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), ClientRequest::STATUSES, true) ? $request->input('status') : 'all',
            'sort' => in_array($request->input('sort'), self::SORTS, true) ? $request->input('sort') : 'last_purchase_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];

        $perPage = in_array((int) $request->input('per_page'), [25, 50, 100], true) ? (int) $request->input('per_page') : 25;

        $clients = $this->filtered($filters)
            ->select(['id', 'company_id', 'institution_id', 'name', 'tax_id', 'sector', 'contact_name', 'phone', 'email', 'status', 'accumulated_amount', 'last_purchase_at'])
            ->with('institution:id,name,acronym')
            ->orderBy($filters['sort'], $filters['direction'])
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Client $client) => $this->row($client));

        $totals = Client::query()->selectRaw('COUNT(*) AS total, COALESCE(SUM(accumulated_amount), 0) AS amount, MAX(last_purchase_at) AS last_purchase')
            ->selectRaw("SUM(CASE WHEN status = 'Activo' THEN 1 ELSE 0 END) AS active")
            ->first();

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'filters' => $filters,
            'stats' => [
                'total' => (int) $totals->total,
                'active' => (int) $totals->active,
                'amount' => (float) $totals->amount,
                'last_purchase_at' => $totals->last_purchase,
            ],
            'can' => [
                'update' => $request->user()->can('update', new Client(['company_id' => $this->activeCompanyId()])),
                'delete' => $request->user()->can('delete', new Client(['company_id' => $this->activeCompanyId()])),
            ],
        ]);
    }

    public function show(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $client->load('institution:id,name,acronym');

        $quotes = Quote::query()->where('client_id', $client->id)
            ->where('outcome', 'Ganada')
            ->latest('awarded_at')
            ->get(['id', 'number', 'total', 'awarded_amount', 'awarded_at', 'status', 'outcome', 'tender_id'])
            ->map(fn (Quote $quote) => [
                'id' => $quote->id,
                'number' => $quote->number,
                'total' => (float) $quote->total,
                'awarded_amount' => $quote->awarded_amount !== null ? (float) $quote->awarded_amount : null,
                'awarded_at' => $quote->awarded_at?->toDateString(),
                'status' => $quote->status,
            ]);

        $contracts = $client->contractsQuery()
            ->latest('id')
            ->get(['id', 'number', 'name', 'amount', 'awarded_amount', 'status', 'start_date', 'end_date', 'created_at'])
            ->map(fn (Contract $contract) => [
                'id' => $contract->id,
                'number' => $contract->number,
                'name' => $contract->name,
                'amount' => (float) ($contract->awarded_amount ?? $contract->amount),
                'status' => $contract->status,
                'start_date' => $contract->start_date?->toDateString(),
                'end_date' => $contract->end_date?->toDateString(),
                'created_at' => $contract->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Clients/Show', [
            'client' => [
                ...$this->row($client),
                'address' => $client->address,
                'notes' => $client->notes,
                'origin' => $client->origin,
                'created_at' => $client->created_at?->toIso8601String(),
            ],
            'quotes' => $quotes,
            'contracts' => $contracts,
            'timeline' => $this->timeline($client, $quotes->all(), $contracts->all()),
            'can' => [
                'update' => $request->user()->can('update', $client),
                'delete' => $request->user()->can('delete', $client) && $contracts->isEmpty(),
                'delete_blocked' => $contracts->isNotEmpty(),
            ],
        ]);
    }

    public function update(ClientRequest $request, Client $client, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $client);

        $client->fill($request->validated());
        $changes = $client->getDirty();
        $client->save();

        if ($changes !== []) {
            $audit->record('cliente.actualizado', $client, $changes);
        }

        return back()->with('success', 'Datos del cliente guardados.');
    }

    public function destroy(Client $client, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $client);

        $deleted = DB::transaction(function () use ($client) {
            $locked = Client::query()->whereKey($client->id)->lockForUpdate()->first();

            if ($locked === null || $locked->contractsQuery()->exists()) {
                return false;
            }

            return (bool) $locked->delete();
        });

        if (! $deleted) {
            return back()->with('error', 'El cliente tiene contratos: no se puede eliminar. Márquelo como inactivo.');
        }

        $audit->record('cliente.eliminado', $client, ['name' => $client->name]);

        return redirect()->route('clients.index')->with('success', "Cliente {$client->name} eliminado.");
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Client::class);

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'status' => $request->input('status', 'all'),
        ];

        $query = $this->filtered($filters)->with('institution:id,name')->orderBy('name');
        $filename = 'clientes-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM para que Excel lea las tildes en UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nombre', 'RTN', 'Institución', 'Sector', 'Contacto', 'Teléfono', 'Correo', 'Estado', 'Monto adjudicado acumulado', 'Último ganado']);

            $query->chunk(500, function ($clients) use ($out) {
                foreach ($clients as $client) {
                    fputcsv($out, SafeCell::csvRow([
                        $client->name,
                        $client->tax_id,
                        $client->institution?->name,
                        $client->sector,
                        $client->contact_name,
                        $client->phone,
                        $client->email,
                        $client->status,
                        number_format((float) $client->accumulated_amount, 2, '.', ''),
                        $client->last_purchase_at?->format('d/m/Y'),
                    ]));
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Client>
     */
    private function filtered(array $filters): Builder
    {
        return Client::query()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('tax_id', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when(in_array($filters['status'] ?? null, ClientRequest::STATUSES, true), fn (Builder $query) => $query->where('status', $filters['status']));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'tax_id' => $client->tax_id,
            'sector' => $client->sector,
            'contact_name' => $client->contact_name,
            'phone' => $client->phone,
            'email' => $client->email,
            'status' => $client->status,
            'accumulated_amount' => (float) $client->accumulated_amount,
            'last_purchase_at' => $client->last_purchase_at?->toDateString(),
            'institution' => $client->institution ? ['id' => $client->institution->id, 'name' => $client->institution->name] : null,
        ];
    }

    /**
     * Línea de tiempo del cliente: alta, cotizaciones ganadas y contratos, lo más reciente arriba.
     *
     * @param  array<int, array<string, mixed>>  $quotes
     * @param  array<int, array<string, mixed>>  $contracts
     * @return array<int, array<string, mixed>>
     */
    private function timeline(Client $client, array $quotes, array $contracts): array
    {
        $items = [];

        foreach ($quotes as $quote) {
            $items[] = ['id' => "q{$quote['id']}", 'title' => "Oferta ganada: {$quote['number']}", 'date' => $quote['awarded_at'], 'icon' => 'trophy-outline', 'status' => 'ok', 'amount' => $quote['awarded_amount'] ?? $quote['total']];
        }

        foreach ($contracts as $contract) {
            $items[] = ['id' => "c{$contract['id']}", 'title' => "Contrato {$contract['number']} ({$contract['status']})", 'date' => $contract['start_date'] ?? substr((string) $contract['created_at'], 0, 10), 'icon' => 'file-sign', 'status' => 'info', 'amount' => $contract['amount']];
        }

        $items[] = ['id' => 'alta', 'title' => 'Cliente registrado por adjudicación', 'date' => $client->created_at?->toDateString(), 'icon' => 'account-plus-outline', 'status' => null, 'amount' => null];

        usort($items, fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']));

        return $items;
    }

    private function activeCompanyId(): ?int
    {
        return app(CompanyContext::class)->currentCompanyId();
    }
}
