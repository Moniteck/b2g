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
use App\Policies\CatalogPolicy;
use App\Policies\ClientPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\ContactPolicy;
use App\Policies\ContractPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\InstitutionPolicy;
use App\Policies\KpiGoalPolicy;
use App\Policies\OperationalPolicy;
use App\Policies\OpportunityPolicy;
use App\Policies\ProcessHistoryPolicy;
use App\Policies\QuotePolicy;
use App\Policies\SigningAssetPolicy;
use App\Policies\TenderPolicy;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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
        InventoryItem::class => OperationalPolicy::class,
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

        Passport::authorizationView(function ($parameters) {
            return view('mcp.authorize', $parameters);
        });

        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));

        // `composer dev` (php artisan dev) levanta también el scheduler, para que
        // los recordatorios y el latido del diagnóstico corran en desarrollo.
        DevCommands::artisan('schedule:work', 'scheduler');

        RateLimiter::for('mcp', fn (Request $request) => [
            Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()),
            Limit::perHour(1000)->by($request->user()?->id ?: $request->ip()),
        ]);
    }
}
