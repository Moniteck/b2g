<?php

namespace App\Providers;

use App\Models\User;
use App\Models\{Contract, Competitor, InstitutionalRelation, CollectionInvoice, BudgetMovement, ManualCashIncome, PoaEntry, Consortium, Document, ComplianceMatrix, NotificationTemplate, Event, TrainingProgress, KpiGoal};
use App\Models\{Company, Client, Opportunity, Quote, Tender};
use App\Policies\ClientPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\ContractPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\KpiGoalPolicy;
use App\Policies\OpportunityPolicy;
use App\Policies\OperationalPolicy;
use App\Policies\QuotePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sin bypass global: cada politica concede el acceso a la raiz de forma
        // explicita, de modo que las restricciones de negocio (por ejemplo, los
        // clientes solo nacen de una adjudicacion) no puedan esquivarse.
        Gate::define('use-ai', fn (User $user) => in_array($user->role, ['admin', 'operator'], true));
        Gate::define('generate-documents', fn (User $user) => in_array($user->role, ['admin', 'operator'], true));
        Gate::define('manage-settings', fn (User $user) => $user->isRoot());
        Gate::define('manage-companies', fn (User $user) => $user->isRoot());
        Gate::define('manage-users', fn (User $user) => $user->isRoot());
        Gate::define('sign-documents', fn (User $user) => $user->isRoot() || $user->companies()->wherePivot('role', 'admin')->exists());
        Gate::define('adjudicate', fn (User $user) => $user->isRoot() || $user->companies()->exists());

        foreach ([Contract::class, Competitor::class, InstitutionalRelation::class, CollectionInvoice::class, BudgetMovement::class, ManualCashIncome::class, PoaEntry::class, Consortium::class, ComplianceMatrix::class, NotificationTemplate::class, Event::class, TrainingProgress::class] as $model) {
            Gate::policy($model, OperationalPolicy::class);
        }

        Gate::policy(KpiGoal::class, KpiGoalPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(Opportunity::class, OpportunityPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Contract::class, ContractPolicy::class);

        Passport::authorizationView(function ($parameters) {
            return view('mcp.authorize', $parameters);
        });

        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));

        RateLimiter::for('mcp', fn (Request $request) => [
            Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()),
            Limit::perHour(1000)->by($request->user()?->id ?: $request->ip()),
        ]);
    }
}
