<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stock ledger learns COLOUR, and the unit each movement was actually transacted in.
 *
 * Until now a ledger row knew only its product. Colour lived on the document LINE
 * (inv_goods_received_note_items.attribute_id), while reference_id points at the document
 * HEADER — so a row reading "GRN 40, product 1" could belong to either of two colours,
 * with no column to say which. That is not a hypothetical: GRN 40 really does receive
 * product 1 in two colours. Colour could therefore never be filtered or reported on, and
 * no join could recover it.
 *
 * entered_unit_id / entered_qty already existed in some databases but in NO migration and
 * NO code — schema drift, so a fresh deploy would not have had them. They are declared
 * here so every environment matches, and they are now actually written: qty_in/qty_out
 * stay in the stocking UOM (the unit the balance is denominated in), while entered_*
 * records what the user really keyed — 10 Roll, not 500 Kg.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_transactions', function (Blueprint $table): void {
            // Soft link, no FK — the Golden Rule: a movement must survive its module.
            if (! Schema::hasColumn('inv_stock_transactions', 'attribute_id')) {
                $table->unsignedBigInteger('attribute_id')->nullable()->after('product_id');
            }

            // Declared, not created, where the drift already put them.
            if (! Schema::hasColumn('inv_stock_transactions', 'entered_unit_id')) {
                $table->unsignedBigInteger('entered_unit_id')->nullable()->after('unit_id');
            }

            if (! Schema::hasColumn('inv_stock_transactions', 'entered_qty')) {
                $table->decimal('entered_qty', 20, 6)->default(0)->after('entered_unit_id');
            }
        });

        Schema::table('inv_stock_transactions', function (Blueprint $table): void {
            $table->index(['product_id', 'attribute_id'], 'idx_stock_product_colour');
        });

        $this->backfillGrnRows();
        $this->backfillDeliveryRows();
    }

    public function down(): void
    {
        Schema::table('inv_stock_transactions', function (Blueprint $table): void {
            $table->dropIndex('idx_stock_product_colour');
            $table->dropColumn(['attribute_id', 'entered_unit_id', 'entered_qty']);
        });
    }

    /**
     * Inbound rows.
     *
     * A roll piece carries stock_transaction_id, so it ties a ledger row to the exact GRN
     * line that produced it — the colour comes back precisely, even on the GRN that
     * received one product in two colours. Non-roll lines have no piece to travel through,
     * so they fall back to matching the document, and only where that (GRN, product) pair
     * used a single colour. An ambiguous row is left NULL: an unknown colour is recoverable
     * later, a wrong one silently poisons every report built on it.
     */
    private function backfillGrnRows(): void
    {
        // Nothing to backfill on a fresh database — and SQLite (the test DB)
        // cannot compile this join-UPDATE at all, so skip it when it's a no-op.
        $hasRows = DB::table('inv_stock_transactions')
            ->where('reference_type', 'grn')
            ->whereNull('attribute_id')
            ->exists();

        if ($hasRows) {
            DB::table('inv_stock_transactions as st')
                ->join('inv_grn_item_pieces as p', 'p.stock_transaction_id', '=', 'st.id')
                ->join('inv_goods_received_note_items as gi', 'gi.id', '=', 'p.grn_item_id')
                ->where('st.reference_type', 'grn')
                ->whereNull('st.attribute_id')
                ->update([
                    'st.attribute_id'    => DB::raw('gi.attribute_id'),
                    'st.entered_unit_id' => DB::raw('gi.unit_id'),
                    'st.entered_qty'     => DB::raw('CASE WHEN gi.conversion_factor > 0 THEN st.qty_in / gi.conversion_factor ELSE st.qty_in END'),
                ]);
        }

        $this->backfillFromDocumentLines(
            referenceType: 'grn',
            lineTable: 'inv_goods_received_note_items',
            headerColumn: 'grn_id',
            qtyColumn: 'qty_in',
        );
    }

    /** Outbound rows: a DO line is colour-specific, so the document match is exact. */
    private function backfillDeliveryRows(): void
    {
        $this->backfillFromDocumentLines(
            referenceType: 'sales_delivery',
            lineTable: 'inv_delivery_order_items',
            headerColumn: 'do_id',
            qtyColumn: 'qty_out',
        );
    }

    /**
     * Resolve a ledger row's colour from its source document's lines, skipping any row
     * whose (document, product) pair carries more than one colour.
     */
    private function backfillFromDocumentLines(
        string $referenceType,
        string $lineTable,
        string $headerColumn,
        string $qtyColumn,
    ): void {
        $rows = DB::table('inv_stock_transactions')
            ->where('reference_type', $referenceType)
            ->whereNull('attribute_id')
            ->get(['id', 'reference_id', 'product_id', $qtyColumn]);

        foreach ($rows as $row) {
            $lines = DB::table($lineTable)
                ->where($headerColumn, $row->reference_id)
                ->where('product_id', $row->product_id)
                ->get(['attribute_id', 'unit_id', 'conversion_factor']);

            $colours = $lines->pluck('attribute_id')->unique();

            if ($colours->count() !== 1 || $colours->first() === null) {
                continue; // ambiguous or unknown — leave NULL rather than guess
            }

            $line   = $lines->first();
            $factor = (float) $line->conversion_factor ?: 1.0;
            $baseQty = (float) $row->{$qtyColumn};

            DB::table('inv_stock_transactions')->where('id', $row->id)->update([
                'attribute_id'    => $line->attribute_id,
                'entered_unit_id' => $line->unit_id,
                'entered_qty'     => $factor > 0 ? $baseQty / $factor : $baseQty,
            ]);
        }
    }
};
