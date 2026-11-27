<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Support\NetPricingBackfill;

return new class extends Migration
{
    /**
     * Switch stored prices from After Tax to Before Tax — price list (product and
     * variant rows) and every SO line. See NetPricingBackfill for the rules.
     *
     * Rehearse first: `php artisan inventory:net-pricing --dry-run` lists every change.
     * Invoices, proformas (other than drafts, re-totalled in 000002) and returns are
     * never written. Old values stay in legacy_* columns; down() restores them.
     */
    public function up(): void
    {
        $backfill = app(NetPricingBackfill::class);

        DB::transaction(function () use ($backfill): void {
            $backfill->applyPriceList($backfill->planPriceList());
            $backfill->applySalesOrderLines($backfill->planSalesOrderLines());
        });
    }

    public function down(): void
    {
        DB::transaction(fn () => app(NetPricingBackfill::class)->revert());
    }
};
