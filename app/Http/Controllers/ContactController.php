<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use App\Models\Institution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contact::class);
        $q = Contact::with('institution:id,name')->orderBy('name');
        if ($s = $request->string('search')->trim()->value()) {
            $q->where(fn ($x) => $x->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        } if ($request->filled('institution_id')) {
            $q->where('institution_id', $request->integer('institution_id'));
        }

        return Inertia::render('Contacts', ['records' => $q->paginate(20)->withQueryString(), 'institutions' => Institution::orderBy('name')->get(['id', 'name']), 'filters' => $request->only('search', 'institution_id')]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        $this->authorize('create', Contact::class);
        Contact::create($request->validated());

        return back()->with('success', 'Contacto creado.');
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $this->authorize('delete', $contact);
        $contact->delete();

        return back()->with('success', 'Contacto eliminado.');
    }
}
