<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Models\ControlAccount;

/**
 * Gives an existing installation the Interest Income account that "New Bank
 * Entry" on Bank Reconciliation credits when the statement shows interest.
 *
 * Only this role is provisioned -- the rest of the chart is left exactly as the
 * client has it, and a mapping someone already set is never overwritten. A
 * fresh install has no chart yet; the seeders create everything later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! ControlAccount::query()->exists()) {
            return;
        }

        (new FinanceGlAccountsSeeder())->run([
            AccountRole::InterestIncome->value,
        ]);
    }

    public function down(): void
    {
        // The account may already carry postings; it is left in place.
    }
};
