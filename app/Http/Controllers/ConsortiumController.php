<?php

namespace App\Http\Controllers;

use App\Models\Consortium;
use App\Models\Tender;
use App\Rules\AllowedCompany;
use App\Rules\BelongsToActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ConsortiumController extends Controller
{
    public function index(Request $r)
    {
        $this->authorize('viewAny', Consortium::class);

        return Inertia::render('Consortia', ['records' => Consortium::with('members')->latest()->paginate(20)]);
    }

    public function store(Request $r): RedirectResponse
    {
        $this->authorize('create', Consortium::class);
        Consortium::create($this->data($r));

        return back();
    }

    public function update(Request $r, Consortium $consortium): RedirectResponse
    {
        $this->authorize('update', $consortium);
        $consortium->update($this->data($r, $consortium));

        return back();
    }

    public function destroy(Consortium $consortium): RedirectResponse
    {
        $this->authorize('delete', $consortium);
        $consortium->delete();

        return back();
    }

    public function member(Request $r, Consortium $consortium): RedirectResponse
    {
        $this->authorize('update', $consortium);
        $data = $this->memberData($r);
        $this->saveMember($consortium, $data);

        return back();
    }

    public function updateMember(Request $r, Consortium $consortium, int $member): RedirectResponse
    {
        $this->authorize('update', $consortium);
        $data = $this->memberData($r);
        DB::transaction(function () use ($consortium, $member, $data) {
            $row = $consortium->members()->findOrFail($member);
            $total = (float) $consortium->members()->whereKeyNot($row->id)->sum('participation_percentage') + (float) $data['participation_percentage'];
            abort_if($total > 100, 422, 'La participación total no puede exceder 100%.');
            $row->update($data);
        });

        return back();
    }

    public function removeMember(Consortium $consortium, int $member): RedirectResponse
    {
        $this->authorize('update', $consortium);
        $consortium->members()->whereKey($member)->delete();

        return back();
    }

    private function data(Request $r, ?Consortium $consortium = null): array
    {
        return $r->validate(['name' => 'required|string|max:255|unique:consortia,name,'.($consortium?->id ?? 'NULL'), 'tender_id' => ['nullable', new BelongsToActiveCompany(Tender::class)], 'institution_id' => 'nullable|exists:institutions,id', 'leader_company_id' => ['nullable', new AllowedCompany], 'amount' => 'nullable|numeric|min:0', 'formed_at' => 'nullable|date', 'status' => 'required|string|max:100', 'notes' => 'nullable|string|max:5000']);
    }

    private function memberData(Request $r): array
    {
        return $r->validate(['name' => 'required|string|max:255', 'company_id' => ['nullable', new AllowedCompany], 'participation_percentage' => 'required|numeric|gt:0|lte:100', 'role' => 'nullable|string|max:255', 'tax_id' => 'nullable|string|max:255', 'email' => 'nullable|email', 'phone' => 'nullable|string|max:255']);
    }

    private function saveMember(Consortium $consortium, array $data): void
    {
        DB::transaction(function () use ($consortium, $data) {
            abort_if((float) $consortium->members()->sum('participation_percentage') + (float) $data['participation_percentage'] > 100, 422, 'La participación total no puede exceder 100%.');
            $consortium->members()->create($data);
        });
    }
}
