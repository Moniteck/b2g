<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consentimiento OAuth del servidor MCP.
 *
 * Passport muestra `resources/views/mcp/authorize.blade.php` (ver `Passport::authorizationView`);
 * esa pantalla envía aquí las empresas y el nivel elegidos. Se valida la elección, se aprueba la
 * solicitud con el controlador de Passport y, solo si emitió el código, se crea o renueva la
 * `McpConnection` del usuario con ese cliente. Sin conexión no hay acceso: el middleware
 * `ApplyMcpConnection` la exige en cada llamada.
 */
class McpConsentController extends Controller
{
    public function approve(Request $request, AuditLogger $audit): Response
    {
        $user = $request->user();
        $authRequest = $this->pendingRequest($request);

        if ($authRequest === null) {
            return response()->view('mcp.expired', [], 419);
        }

        $allowed = $user->accessibleCompanyIds();

        $data = $request->validate([
            'company_ids' => ['required', 'array', 'min:1'],
            'company_ids.*' => ['integer', Rule::in($allowed)],
            'level' => ['required', Rule::in(array_keys(McpConnection::LEVELS))],
        ], [
            'company_ids.required' => 'Elige al menos una empresa.',
            'company_ids.min' => 'Elige al menos una empresa.',
            'company_ids.*.in' => 'Una de las empresas elegidas no está disponible para tu usuario.',
            'level.required' => 'Elige el nivel de acceso.',
            'level.in' => 'Elige el nivel de acceso.',
        ]);

        $clientId = (string) $authRequest->getClient()->getIdentifier();
        $clientName = (string) ($authRequest->getClient()->getName() ?: 'Cliente MCP');

        /** @var Response $response */
        $response = app()->call([app(ApproveAuthorizationController::class), 'approve']);

        if (! $response->isRedirection() || ! str_contains((string) $response->headers->get('Location'), 'code=')) {
            return $response;
        }

        $companyIds = array_values(array_unique(array_map('intval', $data['company_ids'])));

        $connection = DB::transaction(function () use ($user, $clientId, $clientName, $companyIds, $data) {
            $connection = McpConnection::query()->where('user_id', $user->id)->where('client_id', $clientId)->lockForUpdate()->first()
                ?? new McpConnection(['user_id' => $user->id, 'client_id' => $clientId]);

            $connection->forceFill([
                'name' => mb_substr($clientName, 0, 255),
                'client_name' => mb_substr($clientName, 0, 255),
                'company_ids' => $companyIds,
                'scopes' => McpConnection::scopesFor($data['level']),
                'authorized_at' => now(),
                'expires_at' => now()->addDays((int) config('mcp.crm.connection_days', 90)),
                'revoked_at' => null,
                'revoked_by' => null,
            ])->save();

            return $connection;
        });

        $audit->record('mcp.conexion_aprobada', $connection, [
            'cliente' => $clientName,
            'empresas' => $companyIds,
            'nivel' => $data['level'],
        ], $user, $companyIds[0]);

        return $response;
    }

    /**
     * Datos de la pantalla de consentimiento (los lee la vista que arma Passport).
     *
     * @return array<string, mixed>
     */
    public static function screen(User $user, Client $client, ?string $redirectUri): array
    {
        $roles = $user->membershipRoles();
        $previous = McpConnection::query()->where('user_id', $user->id)->where('client_id', (string) $client->getKey())->first();
        $previousCompanies = $previous?->isActive() ? $previous->companyIds() : [];

        $companies = Company::query()
            ->whereIn('id', $user->accessibleCompanyIds())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'role' => Permissions::ROLE_LABELS[$user->isRoot() ? Permissions::ROLE_ADMIN : ($roles[$company->id] ?? Permissions::ROLE_VIEWER)] ?? '',
                'checked' => $previousCompanies === [] ? (int) $company->id === (int) $user->defaultCompanyId() : in_array($company->id, $previousCompanies, true),
            ])
            ->all();

        return [
            'companies' => $companies,
            'level' => old('level', $previous?->isActive() ? $previous->level() : McpConnection::LEVEL_READ),
            'redirectHost' => $redirectUri ? (parse_url($redirectUri, PHP_URL_HOST) ?: $redirectUri) : null,
            'clientHost' => $client->getAttribute('client_uri') ? parse_url((string) $client->getAttribute('client_uri'), PHP_URL_HOST) : null,
            'days' => (int) config('mcp.crm.connection_days', 90),
        ];
    }

    /**
     * Solicitud OAuth pendiente en la sesión, solo si el `auth_token` coincide (sin consumirla:
     * la consume Passport al aprobar).
     */
    protected function pendingRequest(Request $request): ?AuthorizationRequestInterface
    {
        $token = $request->input('auth_token');
        $serialized = $request->session()->get('authRequest');

        if (! is_string($token) || $token === '' || ! hash_equals((string) $request->session()->get('authToken'), $token) || ! is_string($serialized)) {
            return null;
        }

        $authRequest = unserialize($serialized, ['allowed_classes' => [
            AuthorizationRequest::class,
            \Laravel\Passport\Bridge\Client::class,
            Scope::class,
            \Laravel\Passport\Bridge\User::class,
        ]]);

        return $authRequest instanceof AuthorizationRequestInterface ? $authRequest : null;
    }
}
