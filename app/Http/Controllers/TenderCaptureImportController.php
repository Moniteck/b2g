<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenders\CaptureConfirmRequest;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Tender;
use App\Services\Tenancy\CompanyContext;
use App\Services\Tenders\CaptureImportService;
use App\Support\Binaries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importar la captura de la ficha de HonduCompras: subir → OCR → revisar → confirmar.
 * Nada se guarda en la base de datos hasta que la persona confirma la revisión.
 */
class TenderCaptureImportController extends Controller
{
    public function __construct(protected CaptureImportService $imports, protected CompanyContext $context) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Tender::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:'.implode(',', CaptureImportService::imageExtensions()), 'max:'.Binaries::maxImageKilobytes()],
        ], [
            'file.required' => 'Elige la imagen de la captura.',
            'file.mimes' => 'La captura debe ser una imagen PNG, JPG o WebP.',
            'file.max' => 'La imagen supera el máximo de '.(int) (Binaries::maxImageKilobytes() / 1024).' MB.',
        ]);

        $token = $this->imports->read($request->file('file'), $request->user(), $this->companyId());

        return redirect()->route('tenders.import.review', $token);
    }

    public function review(Request $request, string $token): Response|RedirectResponse
    {
        $this->authorize('create', Tender::class);

        $state = $this->imports->state($token, $request->user(), $this->companyId());

        if ($state['confirmed_tender_id'] && Tender::query()->whereKey($state['confirmed_tender_id'])->exists()) {
            return redirect()->route('tenders.show', $state['confirmed_tender_id']);
        }

        return Inertia::render('Tenders/ImportReview', [
            'review' => $this->imports->review($token, $state),
            'statuses' => Tender::STATUSES,
            'modalities' => Tender::MODALITIES,
            'can' => [
                'createInstitution' => $request->user()->can('create', Institution::class),
                'createOpportunity' => $request->user()->can('create', Opportunity::class),
            ],
        ]);
    }

    public function image(Request $request, string $token): BinaryFileResponse
    {
        $this->authorize('create', Tender::class);

        $state = $this->imports->state($token, $request->user(), $this->companyId());
        $path = $this->imports->imagePath($state) ?? abort(404);

        return response()->file($path, [
            'Content-Type' => $state['mime'] ?? 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function confirm(CaptureConfirmRequest $request, string $token): RedirectResponse
    {
        $this->authorize('create', Tender::class);

        $data = $request->validated();

        if (empty($data['institution_id']) && filled($data['new_institution_name'] ?? null)) {
            $this->authorize('create', Institution::class);
        }

        if (! empty($data['create_opportunity'])) {
            $this->authorize('create', Opportunity::class);
        }

        $user = $request->user();
        $state = $this->imports->state($token, $user, $this->companyId());
        $result = $this->imports->confirm($token, $state, $data, $user, $this->companyId());

        $message = $result['created']
            ? "Licitación {$result['tender']->reference} creada desde la captura."
            : "Licitación {$result['tender']->reference} actualizada con la captura.";

        $redirect = redirect()->route('tenders.show', $result['tender'])->with('success', $message);

        return $result['warning'] ? $redirect->with('warning', $result['warning']) : $redirect;
    }

    private function companyId(): int
    {
        return (int) ($this->context->currentCompanyId() ?? abort(403, 'Elige una empresa activa.'));
    }
}
