<?php

namespace App\Http\Controllers;

use App\Models\LegalConversation;
use App\Services\OpenAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class LegalChatController extends Controller
{
    public function index(): Response
    {
        $this->authorize('use-ai');

        return Inertia::render('LegalAgent');
    }

    public function store(Request $request, OpenAIService $openAI): JsonResponse
    {
        $this->authorize('use-ai');

        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        // Las conversaciones son personales: solo se continúa una propia.
        $conversation = isset($data['conversation_id'])
            ? LegalConversation::where('user_id', $request->user()->id)->findOrFail($data['conversation_id'])
            : LegalConversation::create(['user_id' => $request->user()->id, 'title' => str($data['message'])->limit(80)]);

        $conversation->messages()->create(['role' => 'user', 'content' => $data['message']]);

        try {
            $history = $conversation->messages()->latest()->take(12)->get()->reverse()
                ->map(fn ($message) => ['role' => $message->role, 'content' => $message->content])
                ->values()->all();

            $reply = $openAI->legalReply($history);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => $reply]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ]);
    }
}
