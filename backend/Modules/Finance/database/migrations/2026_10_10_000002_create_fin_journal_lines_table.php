<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The general ledger, half two: one row per debit or per credit.
 *
 * Exactly one of debit / credit is non-zero on every row. Two columns rather
 * than one signed amount does allow an invalid state that a signed column
 * could not — accepted deliberately, because two columns are how every ERP
 * stores this and how accountants read a report. JournalPostingService
 * enforces the rule and a test pins it. No DB CHECK constraint: that needs
 * raw DB::statement, which is exactly the MySQL-vs-SQLite divergence that
 * cost us 38 tests in Step 2.
 *
 * ── Accounts are never paired ────────────────────────────────────────────────
 * There is no "from account -> to account" column here, and there must not be.
 * A payment with one debit and three credits has no meaningful pairing. The
 * JOURNAL is the unit that balances, which journal_entry_id already expresses.
 * The UI may render a two-line journal as From -> To (as Tally's Contra
 * voucher does) but that is presentation, never storage.
 *
 * ── party_type / party_id is the subledger ───────────────────────────────────
 * This pair is what makes one shared Accounts Payable account serve unlimited
 * suppliers. "What do we owe ABC Textiles" is a WHERE clause, not a separate
 * account per supplier — which would also hit the 999-per-control-account
 * ceiling and leak every supplier balance to anyone who can read the chart.
 *
 * ── source_type / source_id ──────────────────────────────────────────────────
 * The specific document this line clears, e.g. ('grn', 99). That is what lets
 * Aged Payables age per GRN rather than only per supplier. It deliberately
 * does NOT reference an allocation row id: the payment services delete and
 * re-insert their child rows on every draft save, so those ids are not stable.
 *
 * Account code and name are deliberately NOT snapshotted onto the line,
 * unlike payment_mode_code / bank_name on the settlement tables. A rename
 * SHOULD propagate to historical reports, and restrictOnDelete below
 * guarantees the account can never disappear from under them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_journal_lines', function (Blueprint $table): void {
            $table->id();

            // Both hard FKs: same module, so the Golden Rule does not apply.
            $table->foreignId('journal_entry_id')
                ->constrained('fin_journal_entries')
                ->cascadeOnDelete();

            // restrictOnDelete, not cascade: a posted account must never vanish,
            // because historical reports still have to resolve its code and name.
            $table->foreignId('ledger_account_id')
                ->constrained('fin_ledger_accounts')
                ->restrictOnDelete();

            $table->unsignedSmallInteger('line_no')->default(1);

            $table->decimal('debit', 15, 4)->default(0);
            $table->decimal('credit', 15, 4)->default(0);

            $table->string('description', 255)->nullable();

            // Copied down from the header. The LINE is authoritative for
            // reporting; the header value is only the default. Both carry the
            // column so a report never has to join upward.
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();

            // supplier | customer | employee — soft links, no FK.
            $table->string('party_type', 20)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();

            // The document being cleared — soft link, no FK.
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->timestamps();

            // Named explicitly: the generated names would exceed MySQL's
            // 64-character identifier limit.
            //
            // The first index serves both "balance of this account" and
            // "balance of this account for this party", because party columns
            // follow the account column.
            $table->index(['ledger_account_id', 'party_type', 'party_id'], 'fin_jl_acct_party_idx');
            $table->index(['party_type', 'party_id'], 'fin_jl_party_idx');
            $table->index(['source_type', 'source_id'], 'fin_jl_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_journal_lines');
    }
};
