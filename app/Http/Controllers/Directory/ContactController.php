<?php

namespace App\Http\Controllers\Directory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Directory\ContactRequest;
use App\Models\Contact;
use App\Models\Institution;
use App\Services\System\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contactos de las instituciones compradoras (catálogo compartido del grupo).
 */
class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contact::class);

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'institution_id' => $request->filled('institution_id') ? $request->integer('institution_id') : null,
        ];

        $perPage = in_array((int) $request->input('per_page'), [25, 50, 100], true) ? (int) $request->input('per_page') : 25;

        $contacts = Contact::query()
            ->select(['id', 'institution_id', 'name', 'position', 'department', 'email', 'phone', 'whatsapp'])
            ->with('institution:id,name,acronym')
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('position', 'like', "%{$search}%")))
            ->when($filters['institution_id'], fn (Builder $query, int $id) => $query->where('institution_id', $id))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
            'filters' => $filters,
            'institution' => $filters['institution_id'] ? Institution::query()->find($filters['institution_id'], ['id', 'name', 'acronym']) : null,
            'can' => [
                'create' => $request->user()->can('create', Contact::class),
                'update' => $request->user()->can('update', new Contact),
                'delete' => $request->user()->can('delete', new Contact),
            ],
        ]);
    }

    public function store(ContactRequest $request, AuditLogger $audit): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Contact::class);

        $contact = Contact::create($request->validated());
        $audit->record('contacto.creado', $contact, ['name' => $contact->name, 'institution_id' => $contact->institution_id]);

        if ($request->expectsJson()) {
            return response()->json(['data' => $this->option($contact->load('institution:id,name'))], 201);
        }

        return back()->with('success', "Contacto {$contact->name} creado.");
    }

    public function update(ContactRequest $request, Contact $contact, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $contact);

        $contact->fill($request->validated());
        $changes = $contact->getDirty();
        $contact->save();

        if ($changes !== []) {
            $audit->record('contacto.actualizado', $contact, $changes);
        }

        return back()->with('success', 'Contacto actualizado.');
    }

    public function destroy(Contact $contact, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $contact);

        $contact->delete();
        $audit->record('contacto.eliminado', $contact, ['name' => $contact->name]);

        return back()->with('success', "Contacto {$contact->name} eliminado.");
    }

    /**
     * GET /contactos/buscar?q=&institution_id=  →  { data: [{ id, name, description, institution_id }] }
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contact::class);

        $term = $request->string('q')->trim()->value();

        $contacts = Contact::query()
            ->select(['id', 'institution_id', 'name', 'position', 'email', 'phone'])
            ->with('institution:id,name')
            ->when($request->filled('institution_id'), fn (Builder $query) => $query->where('institution_id', $request->integer('institution_id')))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->when($request->filled('id'), fn (Builder $query) => $query->whereKey($request->integer('id')))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json(['data' => $contacts->map(fn (Contact $contact) => $this->option($contact))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function option(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'institution_id' => $contact->institution_id,
            'position' => $contact->position,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'description' => collect([$contact->position, $contact->institution?->name])->filter()->implode(' · '),
        ];
    }
}
