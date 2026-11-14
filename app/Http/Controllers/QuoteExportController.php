<?php

namespace App\Http\Controllers;

use App\Http\Requests\Quotes\QuoteMailRequest;
use App\Mail\QuoteMail;
use App\Models\Quote;
use App\Services\AuditLogger;
use App\Services\QuoteDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class QuoteExportController extends Controller
{
    /** Compatibilidad: `/cotizaciones/export/pdf?quote=ID` descarga el PDF de una cotización. */
    public function pdf(Request $request, QuoteDocumentService $documents)
    {
        $quote = Quote::query()->findOrFail($request->integer('quote'));
        $this->authorize('view', $quote);

        return response($documents->pdf($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$quote->number}.pdf\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function listPdf(Request $request, QuoteDocumentService $documents)
    {
        $this->authorize('viewAny', Quote::class);

        $quotes = app(QuoteController::class)->filtered($request)->with(['institution:id,name', 'company:id,name'])->limit(2000)->get();

        return response($documents->listPdf($quotes), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="cotizaciones.pdf"',
        ]);
    }

    public function excel(Request $request, QuoteDocumentService $documents)
    {
        $this->authorize('viewAny', Quote::class);

        $quotes = app(QuoteController::class)->filtered($request)->with(['institution:id,name', 'company:id,name'])->limit(10000)->get();
        $path = $documents->spreadsheet($quotes);

        return response()->download($path, 'cotizaciones.xlsx')->deleteFileAfterSend(true);
    }

    /**
     * Envía el PDF por correo a hasta 5 destinatarios (ruta con límite de envíos por usuario).
     */
    public function mail(QuoteMailRequest $request, Quote $quote, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $quote->loadMissing(['company', 'institution', 'contact', 'items']);

        try {
            Mail::to($data['recipients'])->send(new QuoteMail(
                $quote,
                $data['message'] ?? null,
                $data['subject'] ?? null,
                $user->name,
                $user->email,
            ));
        } catch (Throwable $exception) {
            Log::warning('No se pudo enviar la cotización por correo.', ['quote' => $quote->id, 'error' => $exception->getMessage()]);

            return back()->withErrors(['recipients' => 'El servidor de correo no respondió. Vuelva a intentarlo en unos minutos.']);
        }

        $audit->record('cotizacion.enviada', $quote, [
            'numero' => $quote->number,
            'destinatarios' => $data['recipients'],
        ]);

        $count = count($data['recipients']);

        return back()->with('success', "Cotización {$quote->number} enviada a {$count} destinatario".($count > 1 ? 's' : '').'.');
    }
}
