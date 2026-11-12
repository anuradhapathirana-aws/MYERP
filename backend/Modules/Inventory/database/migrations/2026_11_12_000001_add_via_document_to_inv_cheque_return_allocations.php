<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A returned cheque whose advance (credit note) was already spent reopens the
 * invoices / GRNs that credit note paid, on ANOTHER receipt or payment. These
 * columns say which: via_document_id is that receipt / payment (same type as
 * the cheque's own document), credit_note_id the note it spent. Both null for
 * what the cheque's own document settled.
 *
 * via_document_id is what stops that other receipt / payment from being voided
 * afterwards -- its invoices were already reopened by the bounce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_cheque_return_allocations', function (Blueprint $table): void {
            $table->unsignedBigInteger('via_document_id')->nullable()->after('reference_id');
            $table->unsignedBigInteger('credit_note_id')->nullable()->after('via_document_id');

            $table->index('via_document_id', 'inv_cra_via_document_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inv_cheque_return_allocations', function (Blueprint $table): void {
            $table->dropIndex('inv_cra_via_document_idx');
            $table->dropColumn(['via_document_id', 'credit_note_id']);
        });
    }
};
