<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consulta de la bitácora de auditoría de la empresa activa (solo lectura).
 */
class AuditLogController extends Controller
{
    public const CHANNELS = ['web', 'mcp', 'console'];

    public function index(Request $request, CompanyContext $context): Response
    {
        $this->authorize('view-audit');

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'from' => $this->date($request->input('from')),
            'to' => $this->date($request->input('to')),
            'user_id' => $request->filled('user_id') ? $request->integer('user_id') : null,
            'action' => $request->string('action')->trim()->value(),
            'channel' => in_array($request->input('channel'), self::CHANNELS, true) ? $request->input('channel') : 'all',
            'resource_type' => $request->string('resource_type')->trim()->value(),
        ];

        $perPage = in_array((int) $request->input('per_page'), [25, 50, 100], true) ? (int) $request->input('per_page') : 25;

        $logs = AuditLog::query()
            ->select(['id', 'user_id', 'channel', 'actor_name', 'action', 'resource_type', 'resource_id', 'request_id', 'changes', 'ip', 'user_agent', 'created_at'])
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('created_at', '>=', "{$from} 00:00:00"))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->where('created_at', '<=', "{$to} 23:59:59"))
            ->when($filters['user_id'], fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->when($filters['action'], fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($filters['channel'] !== 'all', fn (Builder $query) => $query->where('channel', $filters['channel']))
            ->when($filters['resource_type'], fn (Builder $query, string $type) => $query->where('resource_type', $type))
            ->when($filters['search'], fn (Builder $query, string $term) => $query->where(fn (Builder $builder) => $builder
                ->where('actor_name', 'like', "%{$term}%")->orWhere('action', 'like', "%{$term}%")->orWhere('request_id', $term)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at?->toIso8601String(),
                'actor_name' => $log->actor_name,
                'user_id' => $log->user_id,
                'channel' => $log->channel,
                'action' => $log->action,
                'resource_type' => $log->resource_type,
                'resource_id' => $log->resource_id,
                'request_id' => $log->request_id,
                'changes' => $log->changes,
                'ip' => $log->ip,
                'user_agent' => $log->user_agent,
            ]);

        $companyId = $context->currentCompanyId();

        return Inertia::render('Admin/AuditLog', [
            'logs' => $logs,
            'filters' => $filters,
            'options' => [
                'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')->all(),
                'resource_types' => AuditLog::query()->whereNotNull('resource_type')->distinct()->orderBy('resource_type')->pluck('resource_type')->all(),
                'users' => User::query()
                    ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->select('user_id'))
                    ->orWhereHas('companies', fn (Builder $query) => $query->whereKey($companyId))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name])
                    ->all(),
                'channels' => self::CHANNELS,
            ],
        ]);
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
