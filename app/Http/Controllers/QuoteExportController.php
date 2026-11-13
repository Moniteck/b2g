<?php

namespace App\Http\Controllers;

use App\Mail\QuoteMail;
use App\Models\Quote;
use App\Services\QuoteDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class QuoteExportController extends Controller
{
    public function pdf(Request $request, QuoteDocumentService $documents)
    {
        $quote = Quote::with(['company', 'institution', 'contact', 'items'])->findOrFail($request->integer('quote'));
        $this->authorize('view', $quote);

        return response($documents->pdf($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$quote->number}.pdf\"",
        ]);
    }

    public function listPdf(Request $request, QuoteDocumentService $documents)
    {
        $this->authorize('viewAny', Quote::class);

        $quotes = app(QuoteController::class)->filtered($request)->with(['institution', 'company'])->get();

        return response($documents->listPdf($quotes), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="cotizaciones.pdf"',
        ]);
    }

    public function excel(Request $request, QuoteDocumentService $documents)
    {
        $this->authorize('viewAny', Quote::class);

        $quotes = app(QuoteController::class)->filtered($request)->with(['institution', 'company'])->get();
        $path = $documents->spreadsheet($quotes);

        return response()->download($path, 'cotizaciones.xlsx')->deleteFileAfterSend(true);
    }

    public function mail(Request $request, Quote $quote)
    {
        $this->authorize('view', $quote);

        $data = $request->validate(['email' => ['required', 'email:rfc,dns', 'max:255']]);
        Mail::to($data['email'])->send(new QuoteMail($quote->loadMissing(['company', 'institution', 'contact', 'items'])));

        return back()->with('success', 'Cotización enviada correctamente.');
    }
}
