<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\TrainingProgress;
use App\Support\Permissions;
use App\Support\Tools\CompanyLetterhead;
use App\Support\Tools\SopCatalog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Procedimientos operativos estándar: lectura en pantalla, «Marcar como leído»
 * por usuario y PDF de cada procedimiento.
 */
class SopController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::gate(Permissions::VIEW));

        $read = TrainingProgress::query()->where('user_id', $request->user()->id)
            ->where('tutorial_key', 'like', SopCatalog::PROGRESS_PREFIX.'%')
            ->where('progress', '>=', 100)
            ->pluck('updated_at', 'tutorial_key');

        $sops = collect(SopCatalog::all())->map(fn (array $sop) => $sop + [
            'read_at' => $read->get(SopCatalog::PROGRESS_PREFIX.$sop['slug'])?->toIso8601String(),
            'pdf_url' => route('sops.pdf', $sop['slug']),
        ])->values();

        $selected = SopCatalog::find((string) $request->query('sop')) ? $request->query('sop') : $sops->first()['slug'];

        return Inertia::render('Tools/Sops', ['sops' => $sops, 'selected' => $selected]);
    }

    /**
     * Marca o desmarca el procedimiento como leído por el usuario.
     */
    public function read(Request $request, string $sop): RedirectResponse
    {
        $this->authorize(Permissions::gate(Permissions::VIEW));

        $record = SopCatalog::find($sop);
        abort_if($record === null, 404);

        $read = (bool) $request->validate(['read' => ['required', 'boolean']])['read'];

        TrainingProgress::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'tutorial_key' => SopCatalog::PROGRESS_PREFIX.$record['slug']],
            ['progress' => $read ? 100 : 0],
        );

        return back()->with('success', $read ? "Marcaste «{$record['title']}» como leído." : 'Lectura desmarcada.');
    }

    public function pdf(string $sop)
    {
        $this->authorize(Permissions::gate(Permissions::VIEW));

        $record = SopCatalog::find($sop);
        abort_if($record === null, 404);

        return Pdf::loadView('pdf.tools.sop', [
            'title' => $record['code'].' · '.$record['title'],
            'subtitle' => 'Procedimiento operativo estándar',
            'company' => CompanyLetterhead::current(),
            'generatedAt' => now(),
            'sop' => $record,
        ])->download(strtolower($record['code']).'-'.$record['slug'].'.pdf');
    }
}
