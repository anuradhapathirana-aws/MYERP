<?php

namespace Modules\Finance\Providers;

use App\Contracts\JournalPoster;
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
}
