<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\LegalChatRequest;
use App\Http\Requests\Tools\LegalWritingPdfRequest;
use App\Http\Requests\Tools\LegalWritingRequest;
use App\Models\LegalConversation;
use App\Models\LegalMessage;
use App\Services\Ai\ChatCompletionStream;
use App\Services\Ai\OpenAIService;
use App\Services\System\AuditLogger;
use App\Support\Tools\CompanyLetterhead;
use App\Support\Tools\DocumentTypes;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Agente Legal: chat de orientación sobre contratación pública con historial
 * de conversaciones personales y generador de borradores de escritos.
 *
 * Lo que escribe el usuario es dato: nunca se interpreta como HTML ni como
 * instrucciones del sistema; se guarda y se reenvía tal cual al servicio de IA.
 */
class LegalChatController extends Controller
{
    /** Mensajes previos que se envían como contexto en cada consulta. */
    private const HISTORY = 12;

    public const DISCLAIMER = 'Orientación general generada con IA. No sustituye la asesoría de un abogado: revisa la ley, el pliego y el expediente antes de actuar.';

    public function index(Request $request): Response
    {
        $this->authorize('use-ai');

        return Inertia::render('LegalAgent/Index', [
            'conversations' => $this->conversations($request),
            'writingTypes' => DocumentTypes::legalOptions(),
            'companyName' => CompanyLetterhead::current()['name'] ?? '',
            'disclaimer' => self::DISCLAIMER,
        ]);
    }

    public function show(LegalConversation $conversation): JsonResponse
    {
        $this->authorize('use-ai');
        $this->authorize('view', $conversation);

        $messages = $conversation->messages()->orderBy('id')->get(['id', 'role', 'content', 'created_at']);

        return response()->json([
            'conversation' => ['id' => $conversation->id, 'title' => $conversation->title],
            'messages' => $messages->map(fn (LegalMessage $message) => $this->message($message))->values(),
        ]);
    }

    /**
     * Envía una consulta. La pregunta y la respuesta se guardan juntas solo si la
     * IA responde; si falla, no queda nada a medias y el usuario puede reintentar.
     *
     * Con `Accept: application/json, application/x-ndjson` la respuesta llega en streaming, una línea JSON por
     * evento: `{"type":"delta","text":"…"}` mientras la IA escribe, y al final
     * `{"type":"done","conversation":{…},"messages":[…]}` o `{"type":"error","message":"…"}`.
     * Los errores previos (validación, límite de uso 429, presupuesto, sin clave) siguen siendo
     * respuestas JSON normales, antes de abrir el flujo.
     */
    public function store(LegalChatRequest $request, OpenAIService $openAI, ChatCompletionStream $stream): JsonResponse|StreamedResponse
    {
        $this->authorize('use-ai');

        $data = $request->validated();
        $conversation = null;

        if (isset($data['conversation_id'])) {
            $conversation = LegalConversation::query()->find($data['conversation_id']);
            abort_if($conversation === null, 404, 'La conversación ya no existe.');
            $this->authorize('update', $conversation);
        }

        $history = $conversation
            ? $conversation->messages()->orderByDesc('id')->limit(self::HISTORY - 1)->get(['role', 'content'])->reverse()
                ->map(fn (LegalMessage $message) => ['role' => $message->role, 'content' => $message->content])->values()->all()
            : [];
        $history[] = ['role' => LegalMessage::ROLE_USER, 'content' => $data['message']];
        $userId = $request->user()->id;

        if (str_contains((string) $request->header('Accept'), 'application/x-ndjson')) {
            try {
                $stream->ensureReady();
            } catch (RuntimeException $exception) {
                return response()->json(['message' => $this->aiError($exception)], 422);
            }

            return response()->stream(function () use ($stream, $history, $conversation, $data, $userId) {
                // El servidor de desarrollo y PHP-FPM guardan la salida en búfer: se vacía en cada trozo
                // (en las pruebas ese búfer es el que captura la respuesta y no se toca).
                $live = ! app()->runningUnitTests();
                while ($live && ob_get_level() > 0) {
                    ob_end_flush();
                }
                $emit = function (array $event) use ($live) {
                    echo json_encode($event, JSON_UNESCAPED_UNICODE)."\n";
                    if ($live) {
                        flush();
                    }
                };

                try {
                    $reply = $stream->stream(OpenAIService::legalMessages($history), fn (string $delta) => $emit(['type' => 'delta', 'text' => $delta]));
                } catch (RuntimeException $exception) {
                    $emit(['type' => 'error', 'message' => $this->aiError($exception)]);

                    return;
                }

                $emit(['type' => 'done', ...$this->persist($conversation, $data['message'], $reply, $userId)]);
            }, 200, [
                'Content-Type' => 'application/x-ndjson; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-store',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        try {
            $reply = $openAI->legalReply($history);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $this->aiError($exception)], 422);
        }

        return response()->json($this->persist($conversation, $data['message'], $reply, $userId));
    }

    /**
     * Guarda la pregunta y la respuesta en una transacción (y crea la conversación si es nueva).
     *
     * @return array{conversation: array<string, mixed>, messages: array<int, array<string, mixed>>}
     */
    private function persist(?LegalConversation $conversation, string $message, string $reply, int $userId): array
    {
        [$conversation, $question, $answer] = DB::transaction(function () use ($conversation, $message, $reply, $userId) {
            $conversation ??= LegalConversation::create([
                'user_id' => $userId,
                'title' => Str::limit(Str::squish($message), 80),
            ]);

            $question = $conversation->messages()->create(['role' => LegalMessage::ROLE_USER, 'content' => $message]);
            $answer = $conversation->messages()->create(['role' => LegalMessage::ROLE_ASSISTANT, 'content' => $reply]);
            $conversation->touch();

            return [$conversation, $question, $answer];
        });

        return [
            'conversation' => ['id' => $conversation->id, 'title' => $conversation->title, 'updated_at' => $conversation->updated_at?->toIso8601String()],
            'messages' => [$this->message($question), $this->message($answer)],
        ];
    }

    public function destroy(LegalConversation $conversation, AuditLogger $audit): JsonResponse
    {
        $this->authorize('use-ai');
        $this->authorize('delete', $conversation);

        $conversation->delete();
        $audit->record('legal_conversation.deleted', $conversation);

        return response()->json(['message' => 'Conversación eliminada.']);
    }

    /**
     * Borrador de un escrito legal. No se guarda: el usuario lo copia o lo exporta.
     */
    public function writing(LegalWritingRequest $request, OpenAIService $openAI): JsonResponse
    {
        $this->authorize('use-ai');

        $data = $request->validated();

        try {
            $content = $openAI->legalWriting([
                'tipo_de_escrito' => DocumentTypes::legalLabel($data['type']),
                'empresa_proveedora' => $data['company'],
                'institucion_destinataria' => $data['recipient'],
                'proceso_o_contrato' => $data['process'] ?? null,
                'hechos_y_solicitud' => $data['context'],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $this->aiError($exception)], 422);
        }

        return response()->json(['title' => DocumentTypes::legalLabel($data['type']), 'content' => $content]);
    }

    public function writingPdf(LegalWritingPdfRequest $request)
    {
        $this->authorize('use-ai');

        $data = $request->validated();
        $title = DocumentTypes::legalLabel($data['type']);

        return Pdf::loadView('pdf.tools.text-document', [
            'title' => $title,
            'subtitle' => 'Borrador para revisión profesional',
            'company' => CompanyLetterhead::current(),
            'generatedAt' => now(),
            'content' => $data['content'],
            'notice' => self::DISCLAIMER,
        ])->download(Str::slug($title).'.pdf');
    }

    /** @return array<int, array<string, mixed>> */
    private function conversations(Request $request): array
    {
        return LegalConversation::query()
            ->where('user_id', $request->user()->id)
            ->withCount('messages')
            ->latest('updated_at')->latest('id')
            ->limit(50)
            ->get(['id', 'title', 'updated_at'])
            ->map(fn (LegalConversation $conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->title ?: 'Conversación sin título',
                'updated_at' => $conversation->updated_at?->toIso8601String(),
                'messages_count' => (int) $conversation->messages_count,
            ])->all();
    }

    /** @return array<string, mixed> */
    private function message(LegalMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function aiError(RuntimeException $exception): string
    {
        return $exception->getMessage() ?: 'El servicio de IA no respondió. Inténtalo de nuevo en unos minutos.';
    }
}
