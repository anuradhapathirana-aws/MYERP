<?php

namespace Modules\Inventory\Providers;

use App\Contracts\ChequeReturnSource;
use App\Contracts\DocumentLocationSource;
use App\Contracts\LandedChargeUsage;
use App\Contracts\LandedCostTargets;
use App\Contracts\StockValuationSource;
use App\Contracts\TaxCodeUsage;
use Modules\Inventory\Support\CostingLandedChargeUsage;
use Modules\Inventory\Support\GrnTaxCodeUsage;
use Modules\Inventory\Support\InventoryLandedCostTargets;
use Modules\Inventory\Support\InventoryDocumentLocations;
use Modules\Inventory\Support\InventoryStockValuation;
use Modules\Inventory\Console\Commands\RecalculateStockBalances;
use Modules\Inventory\Console\Commands\VerifyVariantBackfill;
use Modules\Inventory\Console\Commands\WarmPieceQrCodes;
use Modules\Inventory\Services\ChequeReturnService;
use Modules\Inventory\Support\InventoryAmountPrecision;
use App\Contracts\AmountPrecisionUsage;
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

        // The P&L's opening and closing stock: stock on hand by document date
        // at weighted average GRN cost.
        $this->app->bind(StockValuationSource::class, InventoryStockValuation::class);

        // Finance's journal-location backfill: an invoice belongs to its DO's
        // location, a customer return to its own.
        $this->app->bind(DocumentLocationSource::class, InventoryDocumentLocations::class);

        // Bank Reconciliation returns a receipt's or a payment's cheque here:
        // the invoices / GRNs it settled reopen.
        $this->app->tag([ChequeReturnService::class], ChequeReturnSource::TAG);

        // Inventory documents lock the Amount decimals setting; its money
        // columns feed the precision audit.
        $this->app->tag([InventoryAmountPrecision::class], AmountPrecisionUsage::TAG);
    }
}
