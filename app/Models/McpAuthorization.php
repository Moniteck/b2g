<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Autorizacion puntual para una operacion sensible (firmar, eliminar, adjudicar).
 *
 * Se vincula al contenido exacto que se autorizo, de modo que un documento
 * modificado invalida la autorizacion previa.
 */
class McpAuthorization extends Model
{
    protected $fillable = [
        'mcp_connection_id', 'user_id', 'company_id', 'action', 'resource_type',
        'resource_id', 'payload_hash', 'authorized_at', 'expires_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'authorized_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function connection() { return $this->belongsTo(McpConnection::class, 'mcp_connection_id'); }

    public function user() { return $this->belongsTo(User::class); }

    public function isValid(): bool
    {
        if ($this->consumed_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
