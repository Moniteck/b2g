<?php

namespace App\Http\Controllers;

use App\Http\Requests\InstitutionRequest;
use App\Models\Institution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InstitutionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Institution::class);
        $q = Institution::withCount('contacts')->orderBy('name');
        if ($s = $request->string('search')->trim()->value()) {
            $q->where(fn ($x) => $x->where('name', 'like', "%$s%")->orWhere('acronym', 'like', "%$s%"));
        } foreach (['type', 'potential', 'status'] as $field) {
            if ($request->filled($field)) {
                $q->where($field, $request->string($field));
            }
        }

        return Inertia::render('Institutions', ['records' => $q->paginate(20)->withQueryString(), 'filters' => $request->only('search', 'type', 'potential', 'status')]);
    }

    public function update(InstitutionRequest $request, Institution $institution): RedirectResponse
    {
        $this->authorize('update', $institution);
        $institution->update($request->validated());

        return back()->with('success', 'Institución actualizada.');
    }
}
