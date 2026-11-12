<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-line bills with charge types, tax and a GRN link.
 *
 * Line columns:
 *   charge_type_id   what the line is for (nullable: a plain expense line has none)
 *   treatment        the charge type's treatment AS SAVED, so editing a charge
 *                    type later never changes a posted bill
 *   amount           before tax (unchanged meaning)
 *   amount_incl      the amount as typed when the bill's prices include VAT
 *   tax_amount       tax on the line (existing, reserved column -- now used)
 *   line_total       amount + tax: what the supplier is owed for the line
 *   recoverable_tax  the claimable part -> Input Tax Recoverable
 *   reference_type/_id  soft link to the GRN the charge belongs to (Inventory)
 *
 * Existing lines are backfilled to line_total = amount and no tax, which is
 * exactly what their journals posted, so every old bill reads as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_bills', function (Blueprint $table): void {
            $table->boolean('prices_include_tax')->default(false)->after('exchange_rate');
        });

        Schema::table('fin_bill_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('charge_type_id')->nullable()->after('line_no');
            $table->string('treatment', 20)->nullable()->after('charge_type_id');
            $table->decimal('amount_incl', 15, 2)->nullable()->after('amount');
            $table->decimal('line_total', 15, 2)->default(0)->after('tax_amount');
            $table->decimal('recoverable_tax', 15, 2)->default(0)->after('line_total');
            $table->string('reference_type', 30)->nullable()->after('recoverable_tax');
            $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');

            $table->foreign('charge_type_id')->references('id')->on('fin_charge_types')->restrictOnDelete();
            $table->index(['reference_type', 'reference_id'], 'fin_bill_lines_reference_idx');
        });

        Schema::create('fin_bill_line_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bill_line_id')->constrained('fin_bill_lines')->cascadeOnDelete();
            $table->string('tax_code_ref', 20);
            // Null for Import VAT keyed off a CusDec, where only the amount is known.
            $table->decimal('rate', 7, 4)->nullable();
            $table->decimal('base', 15, 2)->nullable();
            $table->decimal('amount', 15, 2);
            $table->boolean('is_recoverable')->default(false);
            $table->timestamps();
        });

        DB::table('fin_bill_lines')->update([
            'line_total' => DB::raw('amount'),
            'tax_amount' => DB::raw('COALESCE(tax_amount, 0)'),
        ]);
        DB::table('fin_bills')->update(['tax_amount' => DB::raw('COALESCE(tax_amount, 0)')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bill_line_taxes');

        Schema::table('fin_bill_lines', function (Blueprint $table): void {
            $table->dropForeign(['charge_type_id']);
            $table->dropIndex('fin_bill_lines_reference_idx');
            $table->dropColumn([
                'charge_type_id', 'treatment', 'amount_incl', 'line_total',
                'recoverable_tax', 'reference_type', 'reference_id',
            ]);
        });

        Schema::table('fin_bills', function (Blueprint $table): void {
            $table->dropColumn('prices_include_tax');
        });
    }
};
