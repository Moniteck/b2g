<?php

namespace App\Http\Controllers;

use App\Http\Requests\Quotes\LoseRequest;
use App\Http\Requests\Quotes\ParticipantsRequest;
use App\Http\Requests\Quotes\RevertRequest;
use App\Http\Requests\Quotes\WinRequest;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Services\Business\AdjudicationResult;
use App\Services\Business\AdjudicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Resultado comercial y participantes, por cotización o por oportunidad (el kanban usa lo segundo).
 *
 * Todas las respuestas son JSON:
 *   POST  .../ganada     { awarded_amount, awarded_at, quote_id? }                 → { message, result }
 *   POST  .../perdida    { winner_participant_id? | winner_name, winner_amount, … } → { message, result }
 *   POST  .../revertir   { reason }                                                 → { message, result }
 *   GET   .../participantes                                                         → { data, summary, can, outcome }
 *   PUT   .../participantes { participants: [...] }                                 → ídem, con message
 *   GET   /oportunidades/{id}/cotizaciones-vivas                                   → { data: [{ id, number, status, total }] }
 * Errores de negocio: 422 con `errors` bajo el campo (p. ej. `outcome`, `winner_name`).
 */
class AdjudicationController extends Controller
{
    public function __construct(protected AdjudicationService $adjudication) {}

    public function win(WinRequest $request): JsonResponse
    {
        $result = $this->adjudication->win($this->subject($request), $request->validated(), $request->user());

        $message = $result->alreadyApplied
            ? 'Esta oferta ya estaba registrada como ganada; no se duplicó nada.'
            : 'Oferta registrada como ganada. Se registró el cliente'.($result->contract ? " y se abrió el contrato {$result->contract->number} en «Pendiente de contrato»." : '.');

        return $this->respond($message, $result);
    }

    public function lose(LoseRequest $request): JsonResponse
    {
        $result = $this->adjudication->lose($this->subject($request), $request->validated(), $request->user());

        $message = $result->alreadyApplied
            ? 'Esta oferta ya estaba registrada como perdida.'
            : 'Oferta registrada como no adjudicada. El ganador quedó entre los participantes.';

        return $this->respond($message, $result);
    }

    public function revert(RevertRequest $request): JsonResponse
    {
        $result = $this->adjudication->revert($this->subject($request), $request->validated('reason'), $request->user());

        $message = 'Resultado revertido: la oferta vuelve a Presentada.'
            .($result->contract ? " El contrato {$result->contract->number} quedó Cancelado." : '')
            .($result->client ? ' El cliente se conserva con el monto descontado.' : '');

        return $this->respond($message, $result);
    }

    public function participants(Request $request): JsonResponse
    {
        $subject = $this->subject($request);
        $this->authorize('view', $subject);

        return response()->json($this->participantsPayload($subject, $request));
    }

    public function saveParticipants(ParticipantsRequest $request): JsonResponse
    {
        $subject = $this->subject($request);

        // El servicio ya devuelve las filas guardadas: no se vuelven a leer (ni el sujeto, que no cambia).
        $participants = $this->adjudication->saveParticipants($subject, $request->validated('participants'), $request->user());

        return response()->json(['message' => 'Participantes guardados.'] + $this->participantsPayload($subject, $request, $participants));
    }

    /**
     * Cotizaciones vivas (sin resultado, ni Sustituidas ni Anuladas) de una oportunidad: «Registrar
     * como Ganada» pide cuál se ganó cuando hay varias. Endpoint ligero: una consulta.
     */
    public function liveQuotes(Request $request): JsonResponse
    {
        $subject = $this->subject($request);
        $this->authorize('view', $subject);

        $quotes = Quote::query()
            ->where('opportunity_id', $subject->getKey())
            ->whereNotIn('status', Quote::CLOSED_STATUSES)
            ->where(fn ($query) => $query->whereNull('outcome')->orWhere('outcome', 'Pendiente'))
            ->orderByDesc('id')
            ->get(['id', 'number', 'status', 'total']);

        return response()->json(['data' => $quotes->map(fn (Quote $quote) => ['id' => $quote->id, 'number' => $quote->number, 'status' => $quote->status, 'total' => (float) $quote->total])->values()]);
    }

    /** Cotización u oportunidad de la ruta (ya resuelta con el scope de empresa: 404 si es de otra). */
    protected function subject(Request $request): Quote|Opportunity
    {
        foreach (['quote' => Quote::class, 'opportunity' => Opportunity::class] as $parameter => $class) {
            $value = $request->route($parameter);

            if ($value instanceof $class) {
                return $value;
            }
            if (is_numeric($value)) {
                $model = $class::query()->findOrFail((int) $value);
                $request->route()->setParameter($parameter, $model);

                return $model;
            }
        }

        abort(404);
    }

    protected function respond(string $message, AdjudicationResult $result): JsonResponse
    {
        return response()->json(['message' => $message, 'result' => $result->toArray()]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function participantsPayload(Quote|Opportunity $subject, Request $request, ?Collection $participants = null): array
    {
        $participants ??= $this->adjudication->participantsOf($subject);
        $user = $request->user();
        $canEdit = $subject instanceof Quote ? $user->can('manageParticipants', $subject) : $user->can('update', $subject);
        $outcome = $subject instanceof Quote
            ? $subject->outcome
            : match ($subject->stage) {
                'adjudicado' => 'Ganada',
                'no_adjudicado' => 'Perdida',
                default => 'Pendiente',
            };

        return [
            'data' => $this->rows($participants),
            'summary' => $this->adjudication->participantSummary($participants),
            'outcome' => $outcome,
            'reference_amount' => $subject instanceof Quote ? (float) $subject->total : (float) $subject->amount,
            'can' => ['edit' => $canEdit],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rows(Collection $participants): array
    {
        return $participants->map(fn (QuoteParticipant $participant) => [
            'id' => $participant->id,
            'name' => $participant->name,
            'tax_id' => $participant->tax_id,
            'amount' => (float) $participant->amount,
            'currency' => $participant->currency,
            'includes_tax' => (bool) $participant->includes_tax,
            'is_ours' => (bool) $participant->is_ours,
            'is_winner' => (bool) $participant->is_winner,
            'position' => $participant->position,
            'notes' => $participant->notes,
        ])->values()->all();
    }
}
