<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\System\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Conexiones MCP» (/configuracion/mcp): accesos concedidos a ChatGPT, Claude u otros clientes.
 *
 * Cada usuario ve y revoca las suyas. El administrador de la empresa activa ve, además, las
 * conexiones de otros usuarios que incluyen esa empresa y puede revocarlas. Revocar invalida
 * todos los tokens del cliente para ese usuario.
 */
class McpConnectionController extends Controller
{
    private const FILTERS = ['active', 'all', 'inactive'];

    public function index(Request $request, CompanyContext $context): Response
    {
        $user = $request->user();
        $companyId = $context->currentCompanyId();
        $isAdmin = $companyId !== null && $user->isCompanyAdmin($companyId);
        $status = in_array($request->input('status'), self::FILTERS, true) ? $request->input('status') : 'active';
        $search = mb_substr($request->string('search')->trim()->value(), 0, 100);

        $connections = $this->visible($user, $companyId, $isAdmin)
            ->with('user:id,name,email')
            ->when($status === 'active', fn (Builder $query) => $query->active())
            ->when($status === 'inactive', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereNotNull('revoked_at')->orWhere('expires_at', '<=', now())))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('client_name', 'like', "%{$search}%")
                ->orWhereHas('user', fn (Builder $users) => $users->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->orderByRaw('CASE WHEN revoked_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->paginate(25, ['id', 'user_id', 'client_name', 'company_ids', 'scopes', 'authorized_at', 'expires_at', 'last_used_at', 'revoked_at', 'created_at'])
            ->withQueryString();

        $companyNames = Company::query()
            ->whereIn('id', $connections->getCollection()->flatMap(fn (McpConnection $c) => $c->companyIds())->unique()->all())
            ->pluck('name', 'id');

        $connections->through(fn (McpConnection $connection) => $this->row($connection, $user, $companyNames->all(), $companyId, $isAdmin));

        $base = $this->visible($user, $companyId, $isAdmin);

        return Inertia::render('Admin/McpConnections', [
            'connections' => $connections,
            'filters' => ['status' => $status, 'search' => $search],
            'stats' => [
                'active' => (clone $base)->active()->count(),
                'mine' => (clone $base)->active()->where('user_id', $user->id)->count(),
            ],
            'serverUrl' => url('/mcp/crm'),
            'scope' => $isAdmin ? 'company' : 'mine',
        ]);
    }

    public function destroy(Request $request, McpConnection $connection, AuditLogger $audit, CompanyContext $context): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->canRevoke($user, $connection, $context->currentCompanyId()), 403, 'No puedes revocar esta conexión.');

        if ($connection->isRevoked()) {
            return back()->with('success', 'La conexión ya estaba revocada.');
        }

        $connection->revoke($user);

        $audit->record('mcp.conexion_revocada', $connection, [
            'cliente' => $connection->client_name,
            'usuario' => $connection->user_id,
        ], $user, $connection->companyIds()[0] ?? null);

        return back()->with('success', "Se revocó el acceso de {$connection->client_name}. Sus tokens dejaron de funcionar.");
    }

    /**
     * @return Builder<McpConnection>
     */
    protected function visible(User $user, ?int $companyId, bool $isAdmin): Builder
    {
        return McpConnection::query()->where(function (Builder $query) use ($user, $companyId, $isAdmin) {
            $query->where('user_id', $user->id);

            if ($isAdmin) {
                $query->orWhereJsonContains('company_ids', $companyId);
            }
        });
    }

    protected function canRevoke(User $user, McpConnection $connection, ?int $companyId): bool
    {
        if ((int) $connection->user_id === (int) $user->id) {
            return true;
        }

        return $companyId !== null && $user->isCompanyAdmin($companyId) && $connection->allowsCompany($companyId);
    }

    /**
     * @param  array<int, string>  $companyNames
     * @return array<string, mixed>
     */
    protected function row(McpConnection $connection, User $user, array $companyNames, ?int $companyId, bool $isAdmin): array
    {
        $status = match (true) {
            $connection->isRevoked() => 'Revocada',
            $connection->isExpired() => 'Vencida',
            default => 'Activa',
        };

        return [
            'id' => $connection->id,
            'client' => $connection->client_name ?: 'Cliente MCP',
            'user' => $connection->user?->name,
            'user_email' => $connection->user?->email,
            'mine' => (int) $connection->user_id === (int) $user->id,
            'companies' => array_values(array_filter(array_map(fn (int $id) => $companyNames[$id] ?? null, $connection->companyIds()))),
            'level' => $connection->level(),
            'level_label' => McpConnection::LEVELS[$connection->level()],
            'status' => $status,
            'authorized_at' => ($connection->authorized_at ?? $connection->created_at)?->toIso8601String(),
            'expires_at' => $connection->expires_at?->toIso8601String(),
            'last_used_at' => $connection->last_used_at?->toIso8601String(),
            'revoked_at' => $connection->revoked_at?->toIso8601String(),
            'can_revoke' => $status !== 'Revocada' && $this->canRevoke($user, $connection, $companyId),
        ];
    }
}
