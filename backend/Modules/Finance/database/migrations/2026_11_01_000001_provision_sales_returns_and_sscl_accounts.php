<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Models\ControlAccount;

/**
 * Gives an existing installation the two accounts the sales-side postings now
 * use: Sales Returns (a Customer Return debits it) and SSCL Payable.
 *
 * Sales Returns is a required role, so without this a live system would refuse
 * every return confirm until someone mapped it by hand. Only these two roles
 * are provisioned -- the rest of the chart is left exactly as the client has it,
 * and a role someone already mapped is never overwritten.
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
            AccountRole::SalesReturns->value,
            AccountRole::SsclPayable->value,
        ]);
    }

    public function down(): void
    {
        // Accounts may already carry postings; they are left in place.
    }
};
