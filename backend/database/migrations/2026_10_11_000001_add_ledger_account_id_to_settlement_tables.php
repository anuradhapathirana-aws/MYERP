<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which cash or bank ACCOUNT the money actually moved through.
 *
 * The settlement line already records bank_id, but a bank is not an account. A
 * company with a current account and a savings account at the same bank has two
 * rows in fin_ledger_accounts and one bank_id, so today's data genuinely cannot
 * say where the money went. That makes a correct credit line impossible, and
 * bank reconciliation impossible after it.
 *
 * It cannot be derived either, which is why this is a stored choice rather than
 * a lookup: only the person making the payment knows.
 *
 * ── Soft link, no foreign key ────────────────────────────────────────────────
 * fin_ledger_accounts belongs to the Finance module, which may not be
 * installed. A real FK here would stop an Inventory-only client from saving a
 * payment at all. Same rule and same shape as
 * core_supplier_groups.default_payable_account_id.
 *
 * ── Nullable, and staying nullable ───────────────────────────────────────────
 * Existing settlements predate this column, and an Inventory-only client will
 * never fill it. The posting path treats a missing value as a reason to fail
 * loudly at confirm time; the column itself stays permissive.
 */
return new class extends Migration
{
    private const TABLES = [
        'inv_supplier_payment_settlements',
        'inv_customer_receipt_settlements',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedBigInteger('ledger_account_id')->nullable()->after('bank_id')->index();
            });
        }

        $this->backfillFromBank();
    }

    /**
     * Point historical rows at the obvious account where there IS one.
     *
     * Only when a bank has exactly one ledger account: with two, the whole
     * reason this column exists is that the answer is unknowable, so guessing
     * would defeat the point. Rows left null simply fail loudly if anyone ever
     * tries to post them.
     *
     * Done in PHP rather than one UPDATE ... JOIN: that syntax is MySQL-only
     * and broke the SQLite test run during Step 2. A loop over a handful of
     * banks is portable and auditable.
     */
    private function backfillFromBank(): void
    {
        if (! Schema::hasTable('fin_ledger_accounts')) {
            return;
        }

        $accountsByBank = DB::table('fin_ledger_accounts')
            ->whereNotNull('bank_id')
            ->whereNull('deleted_at')
            ->get(['id', 'bank_id'])
            ->groupBy('bank_id');

        foreach ($accountsByBank as $bankId => $accounts) {
            if ($accounts->count() !== 1) {
                continue;
            }

            foreach (self::TABLES as $table) {
                DB::table($table)
                    ->where('bank_id', $bankId)
                    ->whereNull('ledger_account_id')
                    ->update(['ledger_account_id' => $accounts->first()->id]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                // Array form so Laravel derives the same conventional name it
                // generated on the way up.
                $t->dropIndex(['ledger_account_id']);
                $t->dropColumn('ledger_account_id');
            });
        }
    }
};
