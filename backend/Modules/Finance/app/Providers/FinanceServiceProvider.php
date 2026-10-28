<?php

namespace Modules\Finance\Providers;

use App\Contracts\JournalPoster;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Finance\Services\JournalPostingService;
use Nwidart\Modules\Support\ModuleServiceProvider;

class FinanceServiceProvider extends ModuleServiceProvider
{
    protected string $name      = 'Finance';
    protected string $nameLower = 'finance';

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Claim the general ledger.
     *
     * AppServiceProvider binds JournalPoster to a no-op by default, so a
     * document can always call it without asking whether Finance exists. This
     * overrides that binding when the module is installed. The entitlement
     * check (module.finance) lives inside the service rather than here,
     * because global_settings is not reliably readable this early in the boot
     * and because the setting can change without a redeploy.
     */
    public function register(): void
    {
        parent::register();

        $this->app->bind(JournalPoster::class, JournalPostingService::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Every Finance write (bills, confirm/void, attachments, controls):
        // 60 a minute per user is far above real data entry and stops a
        // script from flooding the books.
        RateLimiter::for('finance-writes', fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
