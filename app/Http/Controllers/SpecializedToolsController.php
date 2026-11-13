<?php

namespace App\Http\Controllers;

use App\Http\Requests\SpecializedActionRequest;
use App\Models\Company;
use App\Models\Event;
use App\Models\GeneratedDocument;
use App\Models\InventoryItem;
use App\Models\NotificationTemplate;
use App\Models\Quote;
use App\Models\Tender;
use App\Services\Notifications\ReminderService;
use App\Services\OpenAIService;
use App\Services\Tenancy\CompanyContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SpecializedToolsController extends Controller
{
    public function __construct(private CompanyContext $context) {}

    /**
     * Inventario sincronizado desde Blessing. Los precios ya incluyen impuesto.
     */
    public function inventory(Request $request): Response
    {
        $this->authorize('viewAny', InventoryItem::class);

        $query = InventoryItem::query()
            ->when($request->string('search')->trim()->value(), fn ($q, $search) => $q->where(fn ($items) => $items->where('sku', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")))
            ->when($request->string('category')->trim()->value(), fn ($q, $category) => $q->where('category', $category))
            ->when($request->boolean('in_stock'), fn ($q) => $q->where('stock_available', '>', 0));

        $lastSync = InventoryItem::query()->max('synced_at');

        return Inertia::render('SpecializedPage', [
            'kind' => 'inventory',
            'items' => $query->orderBy('sku')->paginate(50)->withQueryString()->through(fn (InventoryItem $item) => [
                'id' => (string) $item->id,
                'sku' => $item->sku,
                'descripcion' => $item->description,
                'categoria' => $item->category,
                'marca' => $item->brand,
                'unidad' => $item->unit,
                'existencia' => (float) ($item->stock_available ?? $item->stock),
                'precio' => $item->salePrice(),
                'tasaImpuesto' => $item->tax_rate !== null ? (float) $item->tax_rate : null,
                'impuestoIncluido' => $item->tax_included,
                'activo' => $item->is_active,
                'sincronizadoEn' => $item->synced_at?->toDateTimeString(),
            ]),
            'categories' => InventoryItem::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'lastSyncAt' => $lastSync?->toDateTimeString(),
        ]);
    }

    public function tenderAi(Request $request): Response
    {
        $this->authorize('use-ai');

        return Inertia::render('SpecializedPage', [
            'kind' => 'tender-ai',
            'tenders' => Tender::query()->with('institution:id,name')->whereIn('status', ['Activa', 'Abierta', 'En preparación'])->latest('published_at')->paginate(30),
        ]);
    }

    public function analyzeTender(SpecializedActionRequest $request, Tender $tender, OpenAIService $openAI): JsonResponse
    {
        $this->authorize('use-ai');
        $this->authorize('view', $tender);
        $data = $request->validated();

        try {
            return response()->json(['analysis' => $openAI->tenderAnalysis($tender, $data['rubrics'] ?? [], $data['context'] ?? '')]);
        } catch (RuntimeException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
    }

    public function offerCalculator(): Response
    {
        $this->authorize('create', Quote::class);

        return Inertia::render('SpecializedPage', ['kind' => 'offer-calculator']);
    }

    /**
     * Calculo de oferta. Los precios de Blessing ya incluyen impuesto, por lo
     * que el total de la linea es cantidad por precio final.
     */
    public function calculateOffer(SpecializedActionRequest $request): JsonResponse
    {
        $this->authorize('create', Quote::class);

        $lines = $request->validated()['lines'] ?? [];

        if (! $lines) {
            return response()->json(['message' => 'Agregue al menos una línea.'], 422);
        }

        $result = collect($lines)->map(function (array $line) {
            $quantity = max((float) ($line['quantity'] ?? 1), 0.01);
            $price = isset($line['price']) && (float) $line['price'] > 0
                ? round((float) $line['price'], 2)
                : round((float) ($line['cost'] ?? 0) * (1 + ((float) ($line['margin'] ?? 25) / 100)), 2);
            $total = round($quantity * $price, 2);
            $rate = (float) ($line['tax_rate'] ?? 0);
            $base = $rate > 0 ? round($total / (1 + ($rate / 100)), 2) : $total;

            return [
                'description' => $line['description'] ?? '',
                'quantity' => $quantity,
                'cost' => (float) ($line['cost'] ?? 0),
                'margin' => (float) ($line['margin'] ?? 25),
                'offer_price' => $price,
                'total' => $total,
                'base' => $base,
                'tax_included' => round($total - $base, 2),
            ];
        });

        return response()->json([
            'lines' => $result,
            'total' => round($result->sum('total'), 2),
            'ranking' => $result->sortBy('total')->values(),
        ]);
    }

    public function documents(Request $request): Response
    {
        $this->authorize('generate-documents');

        return Inertia::render('SpecializedPage', [
            'kind' => 'document-generator',
            'records' => GeneratedDocument::where('user_id', $request->user()->id)->latest()->paginate(20),
            'company' => $this->companySettings(),
        ]);
    }

    public function generateDocument(SpecializedActionRequest $request, OpenAIService $openAI): JsonResponse
    {
        $this->authorize('generate-documents');
        $data = $request->validated();

        try {
            $content = $openAI->businessDocument($data['type'] ?? 'carta_presentacion', $this->companySettings(), $data);
        } catch (RuntimeException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        $data = [...$this->companySettings(), ...$data];

        $document = GeneratedDocument::create([
            'user_id' => $request->user()->id,
            'type' => $data['type'] ?? 'carta_presentacion',
            'title' => ($data['type'] ?? 'Documento').' - '.($data['recipient'] ?? ''),
            'recipient' => $data['recipient'] ?? '',
            'content' => $content,
            'metadata' => $data,
        ]);

        return response()->json(['document' => $document], 201);
    }

    public function documentPdf(Request $request, GeneratedDocument $generatedDocument)
    {
        abort_unless($generatedDocument->user_id === $request->user()->id || $request->user()->isRoot(), 403);

        return Pdf::loadHTML($this->printHtml($generatedDocument->type, $generatedDocument->title, [['description' => nl2br(e($generatedDocument->content)), 'quantity' => '', 'price' => '', 'total' => '']], null))->download("documento-{$generatedDocument->id}.pdf");
    }

    public function notifications(): Response
    {
        $this->authorize('viewAny', NotificationTemplate::class);

        return Inertia::render('SpecializedPage', [
            'kind' => 'notifications',
            'records' => NotificationTemplate::latest()->paginate(30),
        ]);
    }

    public function saveNotification(SpecializedActionRequest $request, ?NotificationTemplate $template = null): RedirectResponse
    {
        $this->authorize($template ? 'update' : 'create', $template ?? NotificationTemplate::class);
        $data = $request->validated();
        $payload = collect($data)->only(['name', 'channel', 'subject', 'body', 'active'])->filter(fn ($value) => $value !== null)->all();

        $template
            ? $template->update($payload)
            : NotificationTemplate::create($payload + ['name' => $data['name'] ?? 'Plantilla', 'body' => $data['body'] ?? '', 'channel' => $data['channel'] ?? 'whatsapp']);

        return back();
    }

    public function deleteNotification(NotificationTemplate $template): RedirectResponse
    {
        $this->authorize('delete', $template);
        $template->delete();

        return back();
    }

    /**
     * Calendario: eventos manuales, plazos derivados de licitaciones y
     * contratos, vencimientos documentales y recordatorios programados.
     */
    public function calendar(Request $request, ReminderService $reminders): Response
    {
        $this->authorize('viewAny', Event::class);

        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now()->addMonths(2)->endOfMonth();

        $records = Event::query()
            ->whereBetween('starts_at', [$from, $to])
            ->with('deliveries')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Event $event) => [
                'id' => (string) $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'type' => $event->type,
                'starts_at' => $event->starts_at?->toDateTimeString(),
                'ends_at' => $event->ends_at?->toDateTimeString(),
                'priority' => $event->priority,
                'completed' => $event->completed,
                'origin' => $event->origin,
                'derived' => $event->isDerived(),
                'reference_type' => $event->reference_type,
                'reference_id' => $event->reference_id,
                'reminders' => $event->deliveries->map(fn ($delivery) => [
                    'canal' => $delivery->channel,
                    'destinatario' => $delivery->recipient,
                    'estado' => $delivery->status,
                    'programado_para' => $delivery->scheduled_for?->toDateTimeString(),
                ])->all(),
            ]);

        return Inertia::render('SpecializedPage', [
            'kind' => 'calendar',
            'records' => $records,
            'types' => Event::TYPES,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function saveEvent(SpecializedActionRequest $request, ?Event $event = null): RedirectResponse
    {
        $this->authorize($event ? 'update' : 'create', $event ?? Event::class);

        $data = $request->validated();
        $payload = collect($data)->only(['title', 'description', 'starts_at', 'ends_at', 'priority', 'completed'])->filter(fn ($value) => $value !== null)->all();
        $payload['completed_at'] = ! empty($payload['completed']) ? now() : null;

        $event
            ? $event->update($payload)
            : Event::create($payload + [
                'user_id' => $request->user()->id,
                'company_id' => $this->context->currentCompanyId(),
                'title' => $data['title'] ?? 'Evento',
                'starts_at' => $data['starts_at'] ?? now(),
                'type' => 'Manual',
                'origin' => 'manual',
            ]);

        return back();
    }

    public function deleteEvent(Request $request, Event $event): RedirectResponse
    {
        // Los eventos derivados (licitación, contrato, documento) no se borran a mano.
        abort_if($event->isDerived(), 422, 'Este evento se genera automáticamente y no se puede eliminar.');
        abort_unless($request->user()->can('delete', $event) || ($event->user_id === $request->user()->id && $request->user()->can('update', $event)), 403);
        $event->delete();

        return back();
    }

    public function training(Request $request): Response
    {
        $this->authorize('view');

        return Inertia::render('SpecializedPage', ['kind' => 'training', 'records' => DB::table('training_progress')->where('user_id', $request->user()->id)->get()]);
    }

    public function saveTraining(SpecializedActionRequest $request): RedirectResponse
    {
        $this->authorize('view');
        $data = $request->validate(['tutorial_key' => ['required', 'string', 'max:100']]) + $request->validated();
        DB::table('training_progress')->updateOrInsert(['user_id' => $request->user()->id, 'tutorial_key' => $data['tutorial_key']], ['progress' => $data['progress'] ?? 0, 'score' => $data['score'] ?? null, 'updated_at' => now(), 'created_at' => now()]);

        return back();
    }

    public function settings(Request $request): Response
    {
        $this->authorize('manage-settings');
        $request->session()->put('settings_reset_token', $token = bin2hex(random_bytes(24)));

        return Inertia::render('SpecializedPage', [
            'kind' => 'settings',
            'settings' => $this->companySettings(),
            'resetToken' => $token,
            'users' => $this->activeCompany()->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->pivot->role]),
            'companies' => Company::whereIn('id', $this->context->allowedCompanyIds())->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function saveSettings(SpecializedActionRequest $request): RedirectResponse
    {
        $this->authorize('manage-settings');

        $company = $this->activeCompany();
        $company->update(['settings' => [...($company->settings ?? []), ...($request->validated()['settings'] ?? [])]]);

        return back();
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $this->authorize('manage-settings');
        $request->validate(['logo' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048']);
        $old = $this->companySettings()['logo'] ?? null;
        if ($old) {
            Storage::disk('local')->delete($old);
        }
        $path = $request->file('logo')->store('company/logo', 'local');
        $company = $this->activeCompany();
        $company->update(['logo_path' => $path, 'settings' => [...($company->settings ?? []), 'logo' => $path]]);

        return back();
    }

    public function settingsPreview()
    {
        $this->authorize('manage-settings');
        $company = $this->companySettings();

        return Pdf::loadHTML($this->printHtml('Vista previa de empresa', $company['name'] ?? 'Empresa', [['description' => $company['address'] ?? '', 'quantity' => '', 'price' => '', 'total' => '']], null))->stream('vista-previa.pdf');
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->authorize('manage-settings');
        abort_unless(hash_equals((string) $request->session()->pull('settings_reset_token'), (string) $request->input('confirmation_token')), 422);
        $this->activeCompany()->update(['settings' => null, 'logo_path' => null]);

        return back();
    }

    public function sops(): Response
    {
        $this->authorize('view');

        return Inertia::render('SpecializedPage', ['kind' => 'sops', 'records' => $this->sopRecords()]);
    }

    public function sopPdf(int $sop)
    {
        $this->authorize('view');

        $record = collect($this->sopRecords())->get($sop);
        abort_unless($record, 404);

        return Pdf::loadHTML($this->printHtml('Procedimiento Operativo', $record['title'], [['description' => $record['content'], 'quantity' => '', 'price' => '', 'total' => '']], null))->download('sop-'.$sop.'.pdf');
    }

    public function legalWriting(SpecializedActionRequest $request, OpenAIService $openAI): JsonResponse
    {
        $this->authorize('use-ai');

        try {
            return response()->json(['content' => $openAI->legalWriting($request->validated())]);
        } catch (RuntimeException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
    }

    /**
     * Empresa activa del usuario. Sin empresa no hay configuración que mostrar.
     */
    private function activeCompany(): Company
    {
        return Company::query()->findOrFail($this->context->currentCompanyId());
    }

    /**
     * Datos de la empresa ACTIVA para membretes y documentos: sus columnas
     * (nombre, RTN, representante) más lo guardado en `companies.settings`.
     */
    private function companySettings(): array
    {
        $company = Company::query()->find($this->context->currentCompanyId());

        if ($company === null) {
            return [];
        }

        return [
            'name' => $company->name,
            'tax_id' => $company->tax_id,
            'representative' => $company->representative,
            ...($company->settings ?? []),
        ];
    }

    private function sopRecords(): array
    {
        return [
            ['title' => 'Gestión de Licitaciones', 'content' => 'Registrar el proceso, verificar requisitos, evaluar capacidad y documentar la decisión.'],
            ['title' => 'Cotizaciones', 'content' => 'Validar inventario, márgenes, vigencia y aprobación antes de emitir la oferta.'],
        ];
    }

    private function printHtml(string $type, string $title, array $lines, ?float $total): string
    {
        $rows = collect($lines)->map(fn ($line) => '<tr><td>'.($line['description'] ?? '').'</td><td>'.($line['quantity'] ?? '').'</td><td>'.($line['price'] ?? '').'</td><td>'.($line['total'] ?? '').'</td></tr>')->join('');

        return '<html><body style="font-family:Arial;color:#1f2937"><h1>'.e($type).'</h1><h2>'.e($title).'</h2><table width="100%" border="1" cellspacing="0" cellpadding="8"><tr><th>Descripción</th><th>Cantidad</th><th>Precio</th><th>Total</th></tr>'.$rows.'</table>'.($total !== null ? '<h2>Total: L '.number_format($total, 2).'</h2>' : '').'</body></html>';
    }
}
