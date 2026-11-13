<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Services\AlertService;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [...parent::share($request), 'auth' => ['user' => $request->user()?->only('id', 'name', 'email', 'role')], 'topbar' => ['alertsCount' => fn () => $request->user() ? count(app(AlertService::class)->active()) : 0], 'flash' => ['success' => fn () => $request->session()->get('success')]];
    }
}
