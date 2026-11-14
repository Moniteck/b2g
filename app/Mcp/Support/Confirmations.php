<?php

namespace App\Mcp\Support;

use App\Models\McpAuthorization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Confirmación en dos pasos de las acciones sensibles por MCP (decisión 8 del diseño).
 *
 *   1.ª llamada (sin `confirmation_code`): no cambia nada; devuelve un resumen y un código.
 *   2.ª llamada (mismos argumentos + `confirmation_code`): consume el código y ejecuta.
 *
 * El código queda atado a usuario, conexión, empresa, acción, recurso y al hash de los
 * argumentos exactos; vence a los 10 minutos y se consume con un único UPDATE condicionado
 * dentro de la misma transacción que la acción (si la acción falla, el código sigue vivo; dos
 * llamadas simultáneas no pueden usarlo dos veces). En la base solo queda su hash.
 */
final class Confirmations
{
    public const ARGUMENT = 'confirmation_code';

    /** Argumentos que no forman parte de lo confirmado. */
    private const IGNORED = [self::ARGUMENT, 'empresa_id'];

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $summary
     * @param  callable(): mixed  $action
     */
    public function guard(McpSession $session, string $action, Model $resource, array $arguments, array $summary, callable $action_): mixed
    {
        $code = $arguments[self::ARGUMENT] ?? null;

        if (! is_string($code) || trim($code) === '') {
            return $this->issue($session, $action, $resource, $arguments, $summary);
        }

        return DB::transaction(function () use ($session, $action, $resource, $arguments, $code, $action_) {
            $this->consume($session, $action, $resource, $arguments, $code);

            return $action_();
        });
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public function issue(McpSession $session, string $action, Model $resource, array $arguments, array $summary): array
    {
        $code = $this->newCode();
        $minutes = (int) config('mcp.crm.confirmation_minutes', 10);

        McpAuthorization::create([
            'mcp_connection_id' => $session->connection->id,
            'user_id' => $session->user->id,
            'company_id' => $resource->getAttribute('company_id'),
            'action' => $action,
            'resource_type' => $resource::class,
            'resource_id' => $resource->getKey(),
            'payload_hash' => self::hash($arguments),
            'code_hash' => self::hashCode($code),
            'summary' => $summary,
            'authorized_at' => now(),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        return [
            'status' => 'confirmation_required',
            'confirmation_code' => $code,
            'expires_at' => now()->addMinutes($minutes)->format('Y-m-d H:i:s'),
            'summary' => $summary,
            'next_step' => "Muestra este resumen a la persona y pídele que confirme. Solo si confirma, repite la llamada con los MISMOS argumentos y confirmation_code=\"{$code}\". El código vence en {$minutes} minutos y sirve una sola vez.",
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function consume(McpSession $session, string $action, Model $resource, array $arguments, string $code): void
    {
        $hash = self::hashCode($code);

        $consumed = McpAuthorization::query()
            ->where('code_hash', $hash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('user_id', $session->user->id)
            ->where('mcp_connection_id', $session->connection->id)
            ->where('action', $action)
            ->where('resource_type', $resource::class)
            ->where('resource_id', $resource->getKey())
            ->where('payload_hash', self::hash($arguments))
            ->update(['consumed_at' => now()]);

        if ($consumed === 1) {
            return;
        }

        $found = McpAuthorization::query()->where('code_hash', $hash)->first();

        throw new ToolError(match (true) {
            $found === null => 'El código de confirmación no es válido. Pide uno nuevo repitiendo la llamada sin confirmation_code.',
            $found->consumed_at !== null => 'Ese código de confirmación ya se usó. Pide uno nuevo repitiendo la llamada sin confirmation_code.',
            $found->expires_at !== null && $found->expires_at->isPast() => 'El código de confirmación venció. Pide uno nuevo repitiendo la llamada sin confirmation_code.',
            default => 'El código de confirmación no corresponde a esta acción, a este registro o a estos datos. Pide uno nuevo repitiendo la llamada sin confirmation_code.',
        });
    }

    /**
     * Hash estable de los argumentos (orden de claves indiferente).
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function hash(array $arguments): string
    {
        $normalize = function ($value) use (&$normalize) {
            if (! is_array($value)) {
                return is_float($value) || is_int($value) ? (string) $value : $value;
            }

            if (Arr::isAssoc($value)) {
                ksort($value);
            }

            return array_map($normalize, $value);
        };

        return hash('sha256', json_encode($normalize(Arr::except($arguments, self::IGNORED)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));
    }

    private function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
    }
}
