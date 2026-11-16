<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\BudgetMovement;
use App\Models\Client;
use App\Models\CollectionInvoice;
use App\Models\Company;
use App\Models\Competitor;
use App\Models\ComplianceMatrix;
use App\Models\Consortium;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Event;
use App\Models\Institution;
use App\Models\InstitutionalRelation;
use App\Models\InventoryItem;
use App\Models\InventorySyncRun;
use App\Models\KpiGoal;
use App\Models\ManualCashIncome;
use App\Models\NotificationTemplate;
use App\Models\Opportunity;
use App\Models\PoaEntry;
use App\Models\ProcessHistory;
use App\Models\Quote;
use App\Models\ReminderDelivery;
use App\Models\ReminderRule;
use App\Models\SignatureApplication;
use App\Models\SigningAsset;
use App\Models\Tender;
use App\Models\User;
use App\Listeners\AuditAuthenticationEvents;
use App\Observers\CalendarSourceObserver;
use App\Policies\CatalogPolicy;
use App\Policies\ClientPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\ContactPolicy;
use App\Policies\ContractPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\InstitutionPolicy;
use App\Policies\InventoryItemPolicy;
use App\Policies\KpiGoalPolicy;
use App\Policies\OperationalPolicy;
use App\Policies\OpportunityPolicy;
use App\Policies\ProcessHistoryPolicy;
use App\Policies\QuotePolicy;
use App\Policies\SigningAssetPolicy;
use App\Policies\TenderPolicy;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use App\Support\TooManyRequests;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Política de cada modelo. Un módulo nuevo añade aquí su línea; si no
     * necesita reglas propias usa OperationalPolicy (recurso con `company_id`)
     * o CatalogPolicy (catálogo compartido por el grupo).
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        Company::class => CompanyPolicy::class,
        Client::class => ClientPolicy::class,
        Quote::class => QuotePolicy::class,
        Opportunity::class => OpportunityPolicy::class,
        Tender::class => TenderPolicy::class,
        Document::class => DocumentPolicy::class,
        Contract::class => ContractPolicy::class,
        KpiGoal::class => KpiGoalPolicy::class,
        ProcessHistory::class => ProcessHistoryPolicy::class,
        Institution::class => InstitutionPolicy::class,
        Contact::class => ContactPolicy::class,
        Competitor::class => CatalogPolicy::class,
        InstitutionalRelation::class => OperationalPolicy::class,
        CollectionInvoice::class => OperationalPolicy::class,
        BudgetMovement::class => OperationalPolicy::class,
        ManualCashIncome::class => OperationalPolicy::class,
        PoaEntry::class => OperationalPolicy::class,
        Consortium::class => OperationalPolicy::class,
        ComplianceMatrix::class => OperationalPolicy::class,
        NotificationTemplate::class => OperationalPolicy::class,
        Event::class => OperationalPolicy::class,
        InventoryItem::class => InventoryItemPolicy::class,
        InventorySyncRun::class => OperationalPolicy::class,
        ReminderRule::class => OperationalPolicy::class,
        ReminderDelivery::class => OperationalPolicy::class,
        SignatureApplication::class => OperationalPolicy::class,
        SigningAsset::class => SigningAssetPolicy::class,
        AuditLog::class => OperationalPolicy::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Sin bypass global (`Gate::before`): la raíz pasa porque cada permiso
        // se evalúa con `User::hasPermission()`, de modo que las restricciones
        // de negocio de las políticas (p. ej. los clientes solo nacen de una
        // adjudicación) no pueden esquivarse.
        //
        // Un gate por permiso de la matriz, con guiones: `use-ai`,
        // `manage-settings`, `view-diagnostics`... evaluado en la empresa activa.
        foreach (array_keys(Permissions::MATRIX) as $permission) {
            Gate::define(Permissions::gate($permission), fn (User $user) => $user->hasPermission($permission));
        }

        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        CalendarSourceObserver::register(); // Fase 6: plazos al calendario.
        EventBus::subscribe(AuditAuthenticationEvents::class); // Seguridad B6: auditoría del acceso.

        Passport::authorizationView(function ($parameters) {
            return view('mcp.authorize', $parameters);
        });

        Passport::tokensExpireIn(now()->addDay()); // MCP: 1 día de acceso y 30 de refresco (Fase 12).
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));

        // `composer dev` (php artisan dev) levanta también el scheduler, para que
        // los recordatorios y el latido del diagnóstico corran en desarrollo.
        DevCommands::artisan('schedule:work', 'scheduler');

        RateLimiter::for('mcp', fn (Request $request) => [
            Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()),
            Limit::perHour(1000)->by($request->user()?->id ?: $request->ip()),
        ]);

        $this->registerCostLimiters();

        // Contraseñas nuevas: 10 caracteres con letras y números; en producción, además, que no
        // aparezcan en filtraciones conocidas (consulta k-anónima a Have I Been Pwned). En pruebas
        // y en local no hay llamadas externas.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->numbers()->uncompromised()
            : Password::min(10)->letters()->numbers());
    }

    /**
     * Límites de las rutas costosas (seguridad, hallazgo A1). Se aplican con `throttle:<nombre>`:
     *
     * - `ai`      llamadas a OpenAI: 5 por minuto por usuario y 100 al día por empresa.
     * - `ocr`     lecturas con Tesseract: 10 por minuto por usuario.
     * - `exports` PDF, XLSX, CSV y ZIP: 20 por minuto por usuario.
     * - `login`   20 por minuto por IP y 20 por hora por correo.
     * - `password-reset` 5 por minuto por IP y 5 por hora por correo.
     *
     * Al superarlos, `TooManyRequests` responde con un aviso en español (toast o JSON 429).
     */
    private function registerCostLimiters(): void
    {
        $user = fn (Request $request) => (string) ($request->user()?->id ?: $request->ip());
        $company = fn (Request $request) => (string) (($request->hasSession() ? $request->session()->get(CompanyContext::SESSION_KEY) : null) ?? $request->user()?->defaultCompanyId() ?? 'sin-empresa');
        $respond = fn (string $what) => fn (Request $request, array $headers) => TooManyRequests::respond(
            $request, TooManyRequests::message((int) ($headers['Retry-After'] ?? 60), $what), $headers,
        );

        RateLimiter::for('ai', fn (Request $request) => [
            Limit::perMinute((int) config('services.openai.per_minute', 5))->by('usuario:'.$user($request))
                ->response($respond('Hiciste muchas consultas a la IA seguidas.')),
            Limit::perDay((int) config('services.openai.per_company_day', 100))->by('empresa:'.$company($request))
                ->response($respond('La empresa alcanzó el límite diario de consultas a la IA.')),
        ]);

        RateLimiter::for('ocr', fn (Request $request) => Limit::perMinute(10)->by($user($request))
            ->response($respond('Subiste muchas capturas seguidas.')));

        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(20)->by($user($request))
            ->response($respond('Generaste muchos archivos seguidos.')));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip())
                ->response($respond('Pediste muchos enlaces seguidos.')),
            Limit::perHour(5)->by('correo:'.Str::lower(trim((string) $request->input('email'))))
                ->response($respond('Pediste muchos enlaces para este correo.')),
        ]);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(20)->by('ip:'.$request->ip())
                ->response($respond('Demasiados intentos de acceso.')),
            Limit::perHour(20)->by('correo:'.Str::lower(trim((string) $request->input('email'))))
                ->response($respond('Demasiados intentos de acceso con este correo.')),
        ]);
    }
}
