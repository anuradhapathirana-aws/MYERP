<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The invoice learns which company issued it.
 *
 * A tax invoice must name its supplier, but an invoice had no path to one: the only
 * route to a Company ran through Location, and only PO/GRN/DO carry a location_id —
 * an invoice carries none, and every delivery order left it NULL anyway. Naming the
 * company on the invoice itself closes that gap for direct/advance invoices too,
 * which have no delivery order to inherit anything from.
 *
 * Soft link, no FK: the Golden Rule — a document must survive its module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable()->after('customer_id');
            $table->index('company_id');
        });

        // Existing invoices predate the column and would print an empty supplier block.
        // Adopt the earliest company so historic documents remain printable; a user can
        // re-point any invoice that belongs to the other company.
        $companyId = DB::table('inv_companies')->orderBy('id')->value('id');

        if ($companyId !== null) {
            DB::table('inv_invoices')->whereNull('company_id')->update(['company_id' => $companyId]);
        }
    }

    public function down(): void
    {
        Schema::table('inv_invoices', function (Blueprint $table): void {
            $table->dropIndex(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
