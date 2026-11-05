<?php

namespace Modules\Inventory\Providers;

use App\Contracts\TaxCodeUsage;
use Modules\Inventory\Support\GrnTaxCodeUsage;
use Modules\Inventory\Console\Commands\RecalculateStockBalances;
use Modules\Inventory\Console\Commands\VerifyVariantBackfill;
use Modules\Inventory\Console\Commands\WarmPieceQrCodes;
use Nwidart\Modules\Support\ModuleServiceProvider;

class InventoryServiceProvider extends ModuleServiceProvider
{
    protected string $name      = 'Inventory';
    protected string $nameLower = 'inventory';

    /** @var string[] */
    protected array $commands = [
        RecalculateStockBalances::class,
        VerifyVariantBackfill::class,
        WarmPieceQrCodes::class,
    ];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // GRN lines reference core tax codes; the master asks before deleting one.
        $this->app->tag([GrnTaxCodeUsage::class], TaxCodeUsage::TAG);
    }
}
