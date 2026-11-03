<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Models\ControlAccount;

/**
 * Gives an existing installation the Purchase Returns account a Supplier Return
 * credits.
 *
 * Purchase Returns is a required role, so without this a live system would
 * refuse every supplier return confirm until someone mapped it by hand. Only
 * this role is provisioned -- the rest of the chart is left exactly as the
 * client has it, and a mapping someone already set is never overwritten.
 *
 * A fresh install has no chart yet; the seeders create everything later, so
 * this does nothing there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! ControlAccount::query()->exists()) {
            return;
        }

        (new FinanceGlAccountsSeeder())->run([
            AccountRole::PurchaseReturns->value,
        ]);
    }

    public function down(): void
    {
        // The account may already carry postings; it is left in place.
    }
};
