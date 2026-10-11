<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The general ledger, half one: the voucher header.
 *
 * Every money movement in the system lands here and in fin_journal_lines.
 * Nothing else is needed — Trial Balance, General Ledger, Balance Sheet,
 * Profit & Loss, Aged Payables and Aged Receivables are all QUERIES over
 * these two tables. No report gets its own table, and no balance is ever
 * stored. That is what makes this design extendable: a new document type
 * adds rows, never columns.
 *
 * ── Link discipline (the Golden Rule) ────────────────────────────────────────
 * Hard FKs only within Finance. Everything pointing outward is a soft link:
 *
 *   source_type / source_id  -> inv_supplier_payments, inv_invoices, ...
 *   company_id               -> inv_companies      (no SoftDeletes there)
 *   location_id              -> inv_locations
 *   fiscal_year_id           -> a table that does not exist yet
 *
 * A hard FK to any inv_ table would mean Finance stops working on a
 * Finance-only installation, which defeats selling the modules separately.
 *
 * ── Immutability ─────────────────────────────────────────────────────────────
 * A posted journal is never edited and never deleted. Corrections post a
 * REVERSING journal and link back through reverses_journal_id. softDeletes
 * exists for drafts only; JournalEntryService::delete() hard-blocks anything
 * already posted, because a deleted journal is a destroyed audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_journal_entries', function (Blueprint $table): void {
            $table->id();

            // JV-2026-0001. Allocated under lockForUpdate() inside the posting
            // transaction, the same contract as inv_purchase_requests.reference_no.
            $table->string('journal_no', 30)->unique();

            // The POSTING date: which accounting period this hits. Every report
            // filters and orders on it.
            $table->date('journal_date')->index();

            // When the source document was actually written. Differs from
            // journal_date for a March invoice booked in April — the same reason
            // the payment tables carry both payment_date and transaction_date.
            $table->date('document_date')->nullable();

            // Single-currency today (see App\Support\Money), but backfilling
            // these across every row later is painful, so they exist from day one.
            $table->char('currency', 3)->default('LKR');
            $table->decimal('exchange_rate', 15, 6)->default(1);

            // general | opening | adjustment | reversal | auto
            $table->string('journal_type', 20)->default('general')->index();

            // Soft polymorphic link to the originating document. NULL for a
            // manual voucher and for a reversal.
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // Financial dimensions. Copied down onto every line so reports
            // never have to join upward.
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('location_id')->nullable()->index();

            $table->text('narration')->nullable();

            // draft | posted | reversed
            $table->string('status', 20)->default('draft')->index();

            // Denormalised from the lines so listing a page of vouchers never
            // aggregates. Written only by the posting service, in the same
            // transaction as the lines, and never touched again afterwards.
            $table->decimal('total_debit', 15, 4)->default(0);
            $table->decimal('total_credit', 15, 4)->default(0);

            // Plain columns rather than self-referencing FKs: the two sides are
            // written in one transaction, posted journals are never deleted, and
            // a self-FK inside Schema::create is the kind of DDL that diverges
            // between MySQL and SQLite.
            $table->unsignedBigInteger('reverses_journal_id')->nullable()->index();
            $table->unsignedBigInteger('reversed_by_journal_id')->nullable();

            // Present now so the fiscal-period table needs no backfill later.
            $table->unsignedBigInteger('fiscal_year_id')->nullable()->index();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Idempotency, enforced by the database: one confirmed document can
            // never post twice. MySQL and SQLite both allow unlimited NULLs in a
            // unique index, so manual vouchers (both columns NULL) are
            // unconstrained. A reversal keeps source_type NULL and links through
            // reverses_journal_id, so it never collides with what it reverses.
            //
            // Named explicitly: the generated name would be long, and MySQL caps
            // identifiers at 64 characters.
            $table->unique(['source_type', 'source_id'], 'fin_je_source_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_journal_entries');
    }
};
