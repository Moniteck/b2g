<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Código de confirmación de un solo uso para una acción sensible por MCP (firmar, eliminar,
 * registrar Ganada/Perdida, revertir).
 *
 * Queda atado al usuario, la conexión, la acción, el recurso y el hash de los parámetros
 * exactos que se resumieron; vence a los 10 minutos y se consume de forma atómica
 * (ver `App\Mcp\Support\Confirmations`). El código en claro nunca se guarda: solo su hash.
 */
class McpAuthorization extends Model
{
    protected $fillable = [
        'mcp_connection_id', 'user_id', 'company_id', 'action', 'resource_type',
        'resource_id', 'payload_hash', 'code_hash', 'summary', 'authorized_at', 'expires_at', 'consumed_at',
    ];

    protected $hidden = ['code_hash', 'payload_hash'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'authorized_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        if ($this->consumed_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
