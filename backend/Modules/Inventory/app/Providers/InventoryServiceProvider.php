<?php

namespace Modules\Inventory\Providers;

use App\Contracts\LandedChargeUsage;
use App\Contracts\LandedCostTargets;
use App\Contracts\TaxCodeUsage;
use Modules\Inventory\Support\CostingLandedChargeUsage;
use Modules\Inventory\Support\GrnTaxCodeUsage;
use Modules\Inventory\Support\InventoryLandedCostTargets;
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

        // Landed costs: bill lines link to GRNs and fill Costing rows; a bill
        // pulled into a costing must not be voided from under it.
        $this->app->bind(LandedCostTargets::class, InventoryLandedCostTargets::class);
        $this->app->tag([CostingLandedChargeUsage::class], LandedChargeUsage::TAG);
    }
}
