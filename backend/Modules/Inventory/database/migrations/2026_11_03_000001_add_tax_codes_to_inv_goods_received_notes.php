<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GRN lines get a tax code (core_tax_codes, soft link) instead of a bare %.
 *
 *   prices_include_tax  the supplier's invoice prices already include VAT
 *   unit_price_incl     the price as typed in that case; unit_price keeps the
 *                       price BEFORE tax, which stock value, batch cost, costing
 *                       and the last-price lookup all read
 *   tax_amount          every tax on the line
 *   recoverable_tax     the part claimable as Input VAT; the rest is cost
 *   inv_grn_item_taxes  each tax separately, for the journal and the VAT return
 *
 * Purely additive. The backfill gives old lines exactly what the old journal
 * posted (gross x tax %, all claimable) — on a database where no line ever
 * carried tax it writes zeros and changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_goods_received_notes', function (Blueprint $table): void {
            $table->boolean('prices_include_tax')->default(false)->after('total_amount');
        });

        Schema::table('inv_goods_received_note_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('tax_code_id')->nullable()->after('discount')->index();
            $table->decimal('unit_price_incl', 15, 4)->nullable()->after('unit_price');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax');
            $table->decimal('recoverable_tax', 15, 2)->default(0)->after('tax_amount');
        });

        Schema::create('inv_grn_item_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grn_item_id')->constrained('inv_goods_received_note_items')->cascadeOnDelete();
            $table->string('tax_code_ref', 20);     // core_taxes.code
            $table->decimal('rate', 5, 2);
            $table->decimal('base', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->boolean('is_recoverable');
            $table->timestamps();

            $table->index('tax_code_ref');
        });

        DB::table('inv_goods_received_note_items')
            ->where('tax', '>', 0)
            ->update([
                'tax_amount'      => DB::raw('ROUND(quantity_received * unit_price * tax / 100, 2)'),
                'recoverable_tax' => DB::raw('ROUND(quantity_received * unit_price * tax / 100, 2)'),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_grn_item_taxes');

        Schema::table('inv_goods_received_note_items', function (Blueprint $table): void {
            $table->dropIndex(['tax_code_id']);
            $table->dropColumn(['tax_code_id', 'unit_price_incl', 'tax_amount', 'recoverable_tax']);
        });

        Schema::table('inv_goods_received_notes', function (Blueprint $table): void {
            $table->dropColumn('prices_include_tax');
        });
    }
};
