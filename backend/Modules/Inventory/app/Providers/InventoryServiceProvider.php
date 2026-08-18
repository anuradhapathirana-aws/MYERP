<?php

namespace Modules\Inventory\Providers;

use Modules\Inventory\Console\Commands\RecalculateStockBalances;
use Nwidart\Modules\Support\ModuleServiceProvider;

class InventoryServiceProvider extends ModuleServiceProvider
{
    protected string $name      = 'Inventory';
    protected string $nameLower = 'inventory';

    /** @var string[] */
    protected array $commands = [
        RecalculateStockBalances::class,
    ];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
