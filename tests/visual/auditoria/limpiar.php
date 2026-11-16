<?php

// Limpieza de los datos que crea la auditoría (prefijo AUD-).
//   php artisan tinker --execute="require 'tests/visual/auditoria/limpiar.php';"           solo lista
//   php artisan tinker --execute="\$borrar = true; require 'tests/visual/auditoria/limpiar.php';"   borra
use App\Models\Contract;
use App\Models\Event;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use Illuminate\Support\Facades\DB;

$borrar = $borrar ?? false;

$tenders = DB::table('tenders')->where('reference', 'like', 'AUD-%')->get(['id', 'reference']);
$quoteIds = DB::table('quote_items')->where('description', 'like', 'AUD-%')->pluck('quote_id')->unique()->values();
$quotes = DB::table('quotes')->whereIn('id', $quoteIds)->get(['id', 'number']);
$contracts = DB::table('contracts')->where(fn ($q) => $q->whereIn('quote_id', $quoteIds)->orWhereIn('tender_id', $tenders->pluck('id')))->get(['id', 'number']);
$opportunities = DB::table('opportunities')->where(fn ($q) => $q->whereIn('tender_id', $tenders->pluck('id'))->orWhere('title', 'like', 'AUD-%'))->get(['id', 'title']);
$events = DB::table('events')->where('title', 'like', '%AUD-%')->get(['id', 'title']);

echo 'Licitaciones: ', $tenders->map(fn ($r) => "{$r->id}={$r->reference}")->implode(', '), PHP_EOL;
echo 'Cotizaciones: ', $quotes->map(fn ($r) => "{$r->id}={$r->number}")->implode(', '), PHP_EOL;
echo 'Contratos: ', $contracts->map(fn ($r) => "{$r->id}={$r->number}")->implode(', '), PHP_EOL;
echo 'Oportunidades: ', $opportunities->map(fn ($r) => "{$r->id}={$r->title}")->implode(', '), PHP_EOL;
echo 'Eventos: ', $events->count(), PHP_EOL;

if ($borrar) {
    DB::transaction(function () use ($tenders, $quotes, $contracts, $opportunities, $events) {
        $models = [
            [Contract::class, $contracts->pluck('id')],
            [Quote::class, $quotes->pluck('id')],
            [Opportunity::class, $opportunities->pluck('id')],
            [Tender::class, $tenders->pluck('id')],
            [Event::class, $events->pluck('id')],
        ];
        foreach ($models as [$class, $ids]) {
            foreach ($class::query()->withoutGlobalScopes()->whereIn('id', $ids)->get() as $model) {
                method_exists($model, 'forceDelete') ? $model->forceDelete() : $model->delete();
            }
        }
    });
    echo 'Borrado.', PHP_EOL;
}
