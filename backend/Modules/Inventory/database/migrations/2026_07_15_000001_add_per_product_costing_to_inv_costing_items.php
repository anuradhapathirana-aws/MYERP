<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Costing redesign: expenses move from ONE common shipment charge (apportioned
 * by value) to PER-PRODUCT amounts — each line carries its own expense-type
 * amounts, SSCL, margin and VAT, all entered PER BASE (stocking) UNIT:
 *
 *   before_tax = fob/cif (GRN price) + Σ expenses + SSCL(% of fob/cif only) + margin
 *   after_tax  = before_tax + VAT%          (after_tax IS selling_price)
 *
 * Before/after-tax are both persisted so VAT and non-VAT invoices can later
 * pick the figure they need. inv_costing_item_expenses holds the typed
 * per-base-unit amount per (line × expense type) — soft links only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_costing_item_expenses', function (Blueprint $table): void {
            $table->id();

            // Soft links — no hard foreign keys across module boundaries
            $table->unsignedBigInteger('costing_id');
            $table->unsignedBigInteger('costing_item_id');
            $table->unsignedBigInteger('grn_item_id');
            $table->unsignedBigInteger('expense_type_id');

            // Typed amount PER BASE (stocking) UNIT of the product
            $table->decimal('amount', 20, 8)->default(0);

            $table->timestamps();

            $table->index('costing_id');
            $table->index('expense_type_id');
            $table->unique(['costing_item_id', 'expense_type_id']);
        });

        Schema::table('inv_costing_items', function (Blueprint $table): void {
            // Per-line % overrides — null = follow the costing header default
            $table->decimal('sscl_pct', 5, 2)->nullable()->after('margin_amount');
            $table->decimal('vat_pct', 5, 2)->nullable()->after('sscl_amount');

            // The per-BASE-UNIT build-up as the user entered/saw it
            $table->decimal('expense_total_base', 20, 8)->default(0)->after('landed_unit_cost_base');
            $table->decimal('margin_amount_base', 20, 8)->default(0)->after('expense_total_base');
            $table->decimal('sscl_amount_base', 20, 8)->default(0)->after('margin_amount_base');
            $table->decimal('vat_amount_base', 20, 8)->default(0)->after('sscl_amount_base');

            // Before-tax selling price (fob/cif + expenses + SSCL + margin) in both
            // denominations; after-tax already exists as selling_price(_base).
            $table->decimal('before_tax_price', 20, 8)->default(0)->after('vat_amount_base');
            $table->decimal('before_tax_price_base', 20, 8)->default(0)->after('before_tax_price');
        });

        // Backfill existing rows from the per-receiving-unit columns they were
        // computed in: before-tax is selling minus VAT; base figures divide by
        // the line's own frozen factor.
        DB::table('inv_costing_items')->update([
            'before_tax_price'      => DB::raw('selling_price - vat_amount'),
            'before_tax_price_base' => DB::raw('(selling_price - vat_amount) / NULLIF(conversion_factor, 0)'),
            'expense_total_base'    => DB::raw('charge_portion / NULLIF(conversion_factor, 0)'),
            'margin_amount_base'    => DB::raw('margin_amount / NULLIF(conversion_factor, 0)'),
            'sscl_amount_base'      => DB::raw('sscl_amount / NULLIF(conversion_factor, 0)'),
            'vat_amount_base'       => DB::raw('vat_amount / NULLIF(conversion_factor, 0)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('inv_costing_items', function (Blueprint $table): void {
            $table->dropColumn([
                'sscl_pct',
                'vat_pct',
                'expense_total_base',
                'margin_amount_base',
                'sscl_amount_base',
                'vat_amount_base',
                'before_tax_price',
                'before_tax_price_base',
            ]);
        });

        Schema::dropIfExists('inv_costing_item_expenses');
    }
};
