<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tax rates an invoice line was posted with, stamped when it is issued.
 *
 *   vat_inclusive_pct -- Non-Tax lines only: the VAT % already inside the price
 *                        (the costing's VAT), split out to Output Tax Payable.
 *   sscl_pct          -- the SSCL rate inside the price at issue time.
 *
 * A customer return reverses exactly these, so changing a rate later never
 * reverses an old invoice at the new rate. Null on lines issued before this
 * existed -- those posted no VAT or SSCL split, and their returns reverse none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_invoice_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('inv_invoice_items', 'vat_inclusive_pct')) {
                $table->decimal('vat_inclusive_pct', 5, 2)->nullable()->after('tax');
            }

            if (! Schema::hasColumn('inv_invoice_items', 'sscl_pct')) {
                $table->decimal('sscl_pct', 5, 2)->nullable()->after('vat_inclusive_pct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inv_invoice_items', function (Blueprint $table): void {
            $table->dropColumn(['vat_inclusive_pct', 'sscl_pct']);
        });
    }
};
