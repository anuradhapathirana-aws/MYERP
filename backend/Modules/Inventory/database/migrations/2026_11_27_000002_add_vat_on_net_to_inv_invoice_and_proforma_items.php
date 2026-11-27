<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'inv_invoice_items'          => ['header' => 'inv_invoices',          'fk' => 'invoice_id'],
        'inv_proforma_invoice_items' => ['header' => 'inv_proforma_invoices', 'fk' => 'proforma_id'],
    ];

    /**
     * VAT is now charged on the line AFTER its discount (it used to be charged on the
     * gross before discount). vat_on_net marks which formula a stored line_total used:
     *  - false: the old rule — every issued / paid / returned line keeps it, untouched,
     *    so its print, returns and postings still reconcile to what the customer was billed.
     *  - true:  the new rule.
     *
     * Only DRAFT invoices and proformas are re-totalled here — they have not been sent
     * to anyone yet, and their next save would re-total them anyway.
     */
    public function up(): void
    {
        foreach (self::TABLES as $itemTable => $meta) {
            if (! Schema::hasColumn($itemTable, 'vat_on_net')) {
                Schema::table($itemTable, function (Blueprint $table): void {
                    $table->boolean('vat_on_net')->default(false)->after('line_total');
                });
            }

            $draftIds = DB::table($meta['header'])
                ->where('status', 'draft')
                ->pluck('id');

            if ($draftIds->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($itemTable, $meta, $draftIds): void {
                foreach ($draftIds->chunk(200) as $chunk) {
                    $lines = DB::table($itemTable)->whereIn($meta['fk'], $chunk)->get();

                    foreach ($lines as $line) {
                        $gross = (float) $line->quantity * (float) $line->unit_price;
                        $net   = $gross - ($gross * (float) $line->discount / 100);

                        DB::table($itemTable)->where('id', $line->id)->update([
                            'line_total' => round($net + ($net * (float) $line->tax / 100), 2),
                            'vat_on_net' => true,
                        ]);
                    }

                    foreach ($chunk as $headerId) {
                        $lineTotals = (float) DB::table($itemTable)->where($meta['fk'], $headerId)->sum('line_total');
                        $transport  = (float) DB::table($meta['header'])->where('id', $headerId)->value('transport_charge');

                        DB::table($meta['header'])->where('id', $headerId)->update([
                            'grand_total' => round($lineTotals + $transport, 2),
                        ]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $itemTable) {
            if (Schema::hasColumn($itemTable, 'vat_on_net')) {
                Schema::table($itemTable, function (Blueprint $table): void {
                    $table->dropColumn('vat_on_net');
                });
            }
        }
    }
};
