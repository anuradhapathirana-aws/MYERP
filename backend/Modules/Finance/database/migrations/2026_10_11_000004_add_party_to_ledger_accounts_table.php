<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a ledger account represent one specific supplier or customer.
 *
 * Two legitimate industry patterns exist for party balances, and this makes the
 * choice a setting rather than a decision baked into the schema:
 *
 *   SHARED     one Accounts Payable for every supplier, told apart by
 *              fin_journal_lines.party_id. What SAP, Oracle and Odoo do.
 *   PER-PARTY  one ledger account per supplier under the same control
 *              account. What Tally and Busy do, and what most accountants
 *              trained in this region expect to see.
 *
 * ── Why the link lives HERE and not on the master ───────────────────────────
 * This table already carries bank_id for exactly the same reason: an account
 * points at the real-world thing it represents. That is IDENTITY, and identity
 * belongs on the account. Contrast core_supplier_groups.default_payable_account_id,
 * which is a PREFERENCE ("route this group's payables here") and correctly
 * lives on the master.
 *
 * The practical payoff is the unique index below: one party gets exactly one
 * account, enforced by the database. A column on the masters could not promise
 * that -- two suppliers could point at one account, or an account could drift
 * loose with no owner.
 *
 * ── Soft link, no foreign key ───────────────────────────────────────────────
 * It is polymorphic, so a real FK is impossible anyway. But it would also be
 * wrong: SupplierMaster is core, while CustomerMaster belongs to Inventory, so
 * a hard FK would reach across a module boundary for customers and not for
 * suppliers -- two different rules for one feature. Same reasoning as the
 * company_id column on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_ledger_accounts', function (Blueprint $table): void {
            // supplier | customer | employee -- the same vocabulary as
            // fin_journal_lines.party_type and App\Support\Journal\PostingRole.
            $table->string('party_type', 20)->nullable()->after('company_id');
            $table->unsignedBigInteger('party_id')->nullable()->after('party_type');

            // One account per party, guaranteed. MySQL and SQLite both allow
            // unlimited NULLs in a unique index, so ordinary accounts (both
            // columns NULL) are completely unconstrained by this.
            $table->unique(['party_type', 'party_id'], 'fin_la_party_unq');
        });
    }

    public function down(): void
    {
        Schema::table('fin_ledger_accounts', function (Blueprint $table): void {
            $table->dropUnique('fin_la_party_unq');
            $table->dropColumn(['party_type', 'party_id']);
        });
    }
};
