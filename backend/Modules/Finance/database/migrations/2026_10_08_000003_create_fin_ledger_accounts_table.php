<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Level 3 of the chart of accounts — the only POSTABLE level.
 *
 * Categories and control accounts are structure; journal entries, payments and
 * bills may reference a ledger account and nothing else. That is the universal
 * rule (SAP posts to G/L accounts, never to account groups; Tally posts to
 * Ledgers, never to Groups) and it is what makes a Trial Balance a simple
 * roll-up instead of a graph walk.
 *
 * `code` is 8 digits: the parent control account code (5) + a 3-digit sequence
 * within that control account. 10101 => 10101001, 10101002, …
 *
 * ── Multi-company ────────────────────────────────────────────────────────────
 * The chart of accounts itself is SHARED across every company in the database,
 * which is how SAP (one chart, many company codes), Dynamics and NetSuite all
 * model it. Account 50201 means Staff Costs in every entity, so a consolidated
 * P&L is a GROUP BY rather than a mapping exercise between rival charts. The
 * company dimension belongs on the POSTING, not on the account.
 *
 * company_id here is the one exception, and it is why it only applies to the
 * cash/bank block: a bank account, a cash box and a petty cash float each
 * belong to exactly one legal entity. SAP draws the same line — the chart is
 * shared, but House Bank (T012) is keyed by company code. NULL for every
 * ordinary account; required whenever cash_book_type is set.
 *
 * ── The cash / bank block ────────────────────────────────────────────────────
 * cash_book_type is NULL for almost every account. The few accounts money
 * actually moves through carry a value, and payment forms filter on it.
 *
 * ── Hard FK vs soft link, and why these three columns differ ─────────────────
 * The Golden Rule in CLAUDE.md forbids hard FKs across MODULE boundaries. Core
 * is NOT a module: it is always installed and is never sold or toggled, so
 * Finance may point at it with a real constraint. core_bank_branches.bank_id
 * already does exactly that.
 *
 * What decides it is how the target deletes:
 *
 *   bank_id        -> core_banks          SoftDeletes  -> HARD FK
 *   bank_branch_id -> core_bank_branches  SoftDeletes  -> HARD FK
 *   company_id     -> inv_companies       hard delete  -> soft link
 *
 * Both bank masters soft-delete, so $bank->delete() is an UPDATE and can never
 * trip restrictOnDelete. The constraint costs nothing at runtime and buys real
 * integrity: a bank_id can never point at a row that is not there.
 *
 * inv_companies has no SoftDeletes, so a hard FK would turn "delete a company"
 * into a 500 unless core's CompanyService learned about Finance tables —
 * precisely the coupling the Golden Rule exists to prevent. It is also how the
 * codebase already models this link: inv_locations.company_id is a soft
 * reference today, for the same reason.
 *
 * Note there is deliberately NO bank_name snapshot column here, unlike
 * inv_customer_receipt_settlements. A master record must always show the
 * bank's CURRENT name; only a posted document freezes what was printed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_ledger_accounts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('control_account_id')
                ->constrained('fin_control_accounts')
                ->restrictOnDelete();

            $table->string('code', 8)->unique();
            $table->string('ledger_account_name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            // CashBookType enum value, or NULL for an ordinary account.
            $table->string('cash_book_type', 20)->nullable()->index();

            // The legal entity that owns this cash/bank account. NULL for every
            // ordinary account — those are shared across all companies.
            $table->unsignedBigInteger('company_id')->nullable()->index();

            // Only meaningful when cash_book_type = cash_book.
            $table->boolean('allows_cash')->default(false);
            $table->boolean('allows_cheque')->default(false);

            // Only meaningful when cash_book_type = bank. Real FKs: core is
            // always installed and both masters soft-delete, so restrictOnDelete
            // can never fire on the normal path — see the note above.
            $table->foreignId('bank_id')
                ->nullable()
                ->constrained('core_banks')
                ->restrictOnDelete();

            $table->foreignId('bank_branch_id')
                ->nullable()
                ->constrained('core_bank_branches')
                ->restrictOnDelete();

            $table->string('bank_account_no', 50)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Named explicitly for the same reason as the other two tables.
            $table->index(['control_account_id', 'ledger_account_name'], 'fin_ledger_ctrl_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_ledger_accounts');
    }
};
