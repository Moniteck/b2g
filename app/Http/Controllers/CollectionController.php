<?php

namespace App\Http\Controllers;

use App\Models\CollectionInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CollectionController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', CollectionInvoice::class);

        return Inertia::render('Collections', ['records' => $this->filtered($request)->with(['client:id,name', 'institution:id,name'])->latest()->paginate(20)->withQueryString(), 'filters' => $request->only('status', 'search')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CollectionInvoice::class);
        CollectionInvoice::create($this->data($request) + ['status' => 'Pendiente']);

        return back();
    }

    public function pay(CollectionInvoice $collectionInvoice): RedirectResponse
    {
        $this->authorize('update', $collectionInvoice);
        DB::transaction(function () use ($collectionInvoice) {
            $invoice = CollectionInvoice::lockForUpdate()->findOrFail($collectionInvoice->id);
            if ($invoice->status === 'Pagada') {
                return;
            } $days = max(0, $invoice->due_at->diffInDays(now(), false));
            $invoice->update(['status' => 'Pagada', 'paid_at' => today(), 'payment_recorded_at' => now(), 'interest_amount' => round((float) $invoice->amount * ((float) $invoice->interest_rate / 100) * $days / 365, 2)]);
        });

        return back();
    }

    public function note(Request $request, CollectionInvoice $collectionInvoice): RedirectResponse
    {
        $this->authorize('update', $collectionInvoice);
        $collectionInvoice->update($request->validate(['collection_note' => 'required|string|max:5000']));

        return back();
    }

    public function whatsapp(CollectionInvoice $collectionInvoice)
    {
        $this->authorize('view', $collectionInvoice);

        return response()->json(['message' => "Estimado cliente, la factura {$collectionInvoice->number} por L. ".number_format((float) $collectionInvoice->amount, 2).' vence el '.$collectionInvoice->due_at->format('d/m/Y').'.']);
    }

    public function destroy(CollectionInvoice $collectionInvoice): RedirectResponse
    {
        $this->authorize('delete', $collectionInvoice);
        $collectionInvoice->delete();

        return back();
    }

    public function pdf(Request $request)
    {
        $this->authorize('viewAny', CollectionInvoice::class);

        return Pdf::loadView('pdf.collections', ['invoices' => $this->filtered($request)->with(['client', 'institution'])->get()])->download('cobros.pdf');
    }

    public function notePdf(CollectionInvoice $collectionInvoice)
    {
        $this->authorize('view', $collectionInvoice);

        return Pdf::loadView('pdf.collection-note', ['invoice' => $collectionInvoice->load(['client', 'institution'])])->download("nota-cobro-{$collectionInvoice->number}.pdf");
    }

    public function xlsx(Request $request)
    {
        $this->authorize('viewAny', CollectionInvoice::class);
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray(['Factura', 'Concepto', 'Cliente', 'Emisión', 'Vencimiento', 'Monto', 'Interés', 'Estado']);
        foreach ($this->filtered($request)->with('client')->get() as $i => $row) {
            $sheet->fromArray([$row->number, $row->concept, $row->client?->name, $row->issued_at->toDateString(), $row->due_at->toDateString(), $row->amount, $row->interest_amount, $row->status], null, 'A'.($i + 2));
        } $path = tempnam(sys_get_temp_dir(), 'collections').'.xlsx';
        (new Xlsx($sheet->getParent()))->save($path);

        return response()->download($path, 'cobros.xlsx')->deleteFileAfterSend(true);
    }

    private function filtered(Request $request)
    {
        $query = CollectionInvoice::query();
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$search}%")->orWhere('concept', 'like', "%{$search}%")->orWhereHas('client', fn ($client) => $client->where('name', 'like', "%{$search}%")));
        }

return $query;
    }

    private function data(Request $request): array
    {
        return $request->validate(['client_id' => 'nullable|exists:clients,id', 'institution_id' => 'nullable|exists:institutions,id', 'number' => 'required|string|max:255|unique:collection_invoices,number', 'concept' => 'required|string|max:255', 'amount' => 'required|numeric|min:0', 'issued_at' => 'required|date', 'due_at' => 'required|date|after_or_equal:issued_at', 'interest_rate' => 'nullable|numeric|between:0,100', 'notes' => 'nullable|string|max:5000']);
    }
}
