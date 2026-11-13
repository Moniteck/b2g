<?php

namespace App\Http\Controllers;

use App\Models\InstitutionalRelation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RelationController extends Controller
{
    public function index(Request $r)
    {
        $this->authorize('viewAny', InstitutionalRelation::class);

        return Inertia::render('Relations', ['records' => InstitutionalRelation::with(['interactions', 'institution:id,name', 'contact:id,name'])->latest()->paginate(20)]);
    }

    public function store(Request $r): RedirectResponse
    {
        $this->authorize('create', InstitutionalRelation::class);
        InstitutionalRelation::create($this->data($r) + ['owner_id' => $r->user()->id]);

        return back();
    }

    public function update(Request $r, InstitutionalRelation $relation): RedirectResponse
    {
        $this->authorize('update', $relation);
        $relation->update($this->data($r));

        return back();
    }

    public function interaction(Request $r, InstitutionalRelation $relation): RedirectResponse
    {
        $this->authorize('update', $relation);
        $d = $r->validate(['type' => 'required|string|max:100', 'description' => 'required|string', 'occurred_at' => 'required|date', 'result' => 'nullable|string|max:255', 'next_action_at' => 'nullable|date']);
        DB::transaction(function () use ($relation, $d) {
            $relation->interactions()->create($d);
            $relation->update(['last_contact_at' => substr($d['occurred_at'], 0, 10), 'next_contact_at' => $d['next_action_at'] ?? $relation->next_contact_at]);
        });

        return back();
    }

    public function destroy(InstitutionalRelation $relation): RedirectResponse
    {
        $this->authorize('delete', $relation);
        $relation->delete();

        return back();
    }

    private function data(Request $r): array
    {
        return $r->validate(['institution_id' => 'required|exists:institutions,id', 'contact_id' => 'nullable|exists:contacts,id', 'relationship_type' => 'nullable|string|max:255', 'influence' => 'nullable|string|max:255', 'channel' => 'nullable|string|max:255', 'status' => 'required|string|max:100', 'started_at' => 'nullable|date', 'next_contact_at' => 'nullable|date', 'poa_interest' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:5000']);
    }
}
