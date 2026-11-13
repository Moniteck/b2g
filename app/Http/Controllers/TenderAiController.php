<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Services\AuditLogger;
use App\Services\OpenAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Recomendación de la IA sobre una licitación. Vive en la pestaña «Análisis del pliego» de la
 * ficha; la antigua pantalla `/ia-licitaciones` redirige a la lista de licitaciones.
 */
class TenderAiController extends Controller
{
    public function index(): RedirectResponse
    {
        $this->authorize('viewAny', Tender::class);

        return redirect()->route('tenders.index', ['window' => 'open'])
            ->with('success', 'El análisis con IA ahora está en la ficha de cada licitación, pestaña «Análisis del pliego».');
    }

    public function recommend(Request $request, Tender $tender, OpenAIService $openAI, AuditLogger $audit): JsonResponse
    {
        $this->authorize('use-ai');
        $this->authorize('view', $tender);

        $data = $request->validate([
            'context' => ['nullable', 'string', 'max:4000'],
            'rubrics' => ['nullable', 'array', 'max:10'],
            'rubrics.*' => ['string', 'max:120'],
        ]);

        $analysis = $tender->extractions()->where('kind', 'pliego')->whereIn('status', ['review', 'confirmed'])->latest('id')->first();
        $context = trim(implode("\n\n", array_filter([
            $data['context'] ?? null,
            $analysis ? 'Datos del pliego (extraídos y verificados): '.json_encode($analysis->extracted['lists'] ?? [], JSON_UNESCAPED_UNICODE) : null,
        ])));

        try {
            $text = $openAI->tenderAnalysis($tender, $data['rubrics'] ?? [], mb_substr($context, 0, 30000));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $audit->record('licitacion.recomendacion_ia', $tender, ['tokens' => $openAI->lastUsage()['total_tokens'] ?? null]);

        return response()->json(['analysis' => $text]);
    }
}
