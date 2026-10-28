<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\JournalPoster;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\SettingsService;
use App\Support\Journal\NullJournalPoster;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);

        // Scoped, not singleton: it memoises each user's companies, and that
        // memo must not outlive the request (or leak between queue jobs).
        $this->app->scoped(CompanyAccessService::class);

        // Default: no general ledger. FinanceServiceProvider binds its own
        // posting service when the module is installed, so a document can
        // always call JournalPoster without asking whether Finance exists. On
        // an Inventory-only installation the no-op runs and nothing posts,
        // which is the correct outcome rather than a degraded one.
        //
        // bindIf, not bind: nwidart registers module providers BEFORE the app
        // providers listed in bootstrap/providers.php, so a plain bind here
        // would overwrite the binding Finance just made. Paired with Finance's
        // unconditional bind, the outcome is the same whichever order they
        // register in -- Finance always wins, core only fills the gap.
        $this->app->bindIf(JournalPoster::class, NullJournalPoster::class);
    }

    public function boot(): void
    {
        // super_admin bypasses every Gate check implicitly
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->hasRole('super_admin') ? true : null;
        });
    }
}
