<?php

namespace App\Services\Documents;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Error controlado al firmar (PDF dañado, cifrado, página inexistente, sin
 * firma...). Se responde como 422 con un mensaje claro, nunca como 500.
 */
class SignatureException extends RuntimeException
{
    public function __construct(string $message, protected string $field = 'file', protected int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => [$this->field => [$this->getMessage()]],
            ], $this->status);
        }

        return back()->withErrors([$this->field => $this->getMessage()])->with('error', $this->getMessage());
    }
}
