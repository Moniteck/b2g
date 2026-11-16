<?php

namespace App\Http\Controllers\Tenders;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Services\Ai\OpenAIService;
use App\Services\System\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use RuntimeException;

/**
 * Recomendación de la IA sobre una licitación. Vive en la pestaña «Análisis del pliego» de la
 * ficha; `/ia-licitaciones` muestra las vigentes con ese aviso.
 */
class TenderAiController extends Controller
{
    /**
     * Vista de recomendaciones: las licitaciones vigentes, con el aviso de que el análisis con IA
     * se hace en la ficha de cada una (pestaña «Análisis del pliego»).
     */
    public function index(Request $request, TenderController $tenders): Response
    {
        $this->authorize('use-ai');

        $request->query->set('window', 'open');

        return $tenders->index($request)->with('aiView', true);
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
