<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Institution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La base de clientes es de solo lectura para el usuario: los clientes se
 * crean unicamente cuando una oferta se registra como ganada.
 */
class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $query = Client::query()->with('institution:id,name')->latest('last_purchase_at')->latest('id');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($builder) => $builder->where('name', 'like', "%{$search}%")
                ->orWhere('tax_id', 'like', "%{$search}%")
                ->orWhere('sector', 'like', "%{$search}%"));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return Inertia::render('Clients', [
            'records' => $query->paginate(20)->withQueryString()->through(fn (Client $client) => [
                'id' => (string) $client->id,
                'nombre' => $client->name,
                'rtn' => $client->tax_id,
                'tipo' => $client->type,
                'sector' => $client->sector,
                'contacto' => $client->contact_name,
                'telefono' => $client->phone,
                'correo' => $client->email,
                'estado' => $client->status,
                'montoAcumulado' => (float) $client->accumulated_amount,
                'ultimaCompra' => $client->last_purchase_at?->toDateString(),
                'origen' => $client->origin,
                'cotizacionOrigen' => $client->source_quote_id,
                'notas' => $client->notes,
            ]),
            'institutions' => Institution::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('search', 'status'),
            'origenUnico' => 'Los clientes se crean automaticamente al registrar una oferta ganada.',
        ]);
    }

    /**
     * Actualizacion de los datos de contacto y seguimiento.
     */
    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // El RTN es único dentro de la empresa del cliente.
            'tax_id' => ['nullable', 'string', 'max:100', Rule::unique('clients', 'tax_id')->where('company_id', $client->company_id)->ignore($client)],
            'type' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $client->update($data);

        return back()->with('success', 'Cliente actualizado.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);

        $client->delete();

        return back()->with('success', 'Cliente eliminado.');
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Client::class);

        $clients = $this->filtered($request)->get();

        return response()->streamDownload(function () use ($clients) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Nombre', 'RTN', 'Sector', 'Contacto', 'Telefono', 'Email', 'Estado', 'Monto acumulado', 'Ultima compra']);
            foreach ($clients as $client) {
                fputcsv($out, [
                    $client->name,
                    $client->tax_id,
                    $client->sector,
                    $client->contact_name,
                    $client->phone,
                    $client->email,
                    $client->status,
                    $client->accumulated_amount,
                    $client->last_purchase_at?->toDateString(),
                ]);
            }
            fclose($out);
        }, 'clientes.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtered(Request $request)
    {
        return Client::query()
            ->when($request->string('search')->trim()->value(), fn ($query, $search) => $query->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('tax_id', 'like', "%{$search}%")
                ->orWhere('sector', 'like', "%{$search}%")))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')));
    }
}
