<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which ledger account plays which role in a posting. Setup, not transactions.
 *
 * This is the table that lets Inventory ask for 'accounts_payable' instead of
 * account id 20101001. Without it, Inventory would have to read
 * fin_ledger_accounts to resolve an account — and Inventory would then stop
 * working whenever Finance is not sold.
 *
 * It is also why a new document type adds ROWS, never columns, and why the
 * Bill Entry switch later is a one-row update rather than a code change: the
 * GRN's credit side simply repoints from accounts_payable to grni_clearing.
 *
 * ── Nothing here is a decision the client has to make ────────────────────────
 * The set of roles is fixed by double-entry bookkeeping, not by the client's
 * business. FinanceGlAccountsSeeder therefore creates each ledger account AND
 * its row here in one step, so the mapping arrives complete. The Finance
 * Settings screen exists to SHOW it, and to handle the rare case of a chart
 * that genuinely differs — not to set it up from nothing.
 *
 * ── Resolution order, most specific first ────────────────────────────────────
 *   1. explicit ledger_account_id on the document   (the bank account picked
 *      on screen — never comes from this table)
 *   2. core_supplier_groups.default_payable_account_id   for payables
 *   3. this table, matching (rule_key, company_id)
 *   4. this table, matching (rule_key, NULL)        the global default
 *   5. FAIL with a readable 422 — never post to a guessed account
 *
 * company_id is a soft link (inv_companies has no SoftDeletes), present so a
 * group with separate legal entities can override one role per company without
 * a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_account_defaults', function (Blueprint $table): void {
            $table->id();

            // accounts_payable, sales_revenue, output_tax_payable, ...
            // See Modules\Finance\Enums\AccountRole for the full set and the
            // account types each one accepts.
            $table->string('rule_key', 60);

            // Hard FK: same module. restrictOnDelete so a mapped account cannot
            // be deleted out from under the posting logic.
            $table->foreignId('ledger_account_id')
                ->constrained('fin_ledger_accounts')
                ->restrictOnDelete();

            $table->unsignedBigInteger('company_id')->nullable();

            $table->string('description', 255)->nullable();

            $table->timestamps();

            // One account per role per company. NULL company_id is the global
            // default, and MySQL/SQLite both treat NULLs as distinct in a
            // unique index — so the global row and any number of per-company
            // rows coexist.
            $table->unique(['rule_key', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_account_defaults');
    }
};
