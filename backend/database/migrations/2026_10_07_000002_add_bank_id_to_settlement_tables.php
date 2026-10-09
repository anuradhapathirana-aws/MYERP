<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link settlements to the bank master, completing the link+snapshot pattern the
 * settlement row already uses for payment modes:
 *
 *     payment_mode_id    <- live link, survives renames, used for reporting
 *     payment_mode_name  <- snapshot, printed on the document
 *
 * bank_name had only the snapshot half. That meant renaming a bank in the
 * master silently stranded every historical settlement that referenced it by
 * name — a single-character typo did exactly that during testing.
 *
 * bank_name is deliberately KEPT, not replaced. A cheque physically said
 * "Hatton National Bank"; the receipt PDF prints that stored value
 * (customer_receipt.blade.php reads $s->bank_name), so a reprint must stay
 * identical even if the master is later renamed, merged or deleted. The id is
 * for joins and reporting, the text is the audit record.
 *
 * No foreign key: this matches the project's convention for links that cross an
 * ownership boundary (see inv_supplier_masters.supplier_group_id) and keeps the
 * column resilient if a bank row is ever removed.
 */
return new class extends Migration
{
    private const TABLES = [
        'inv_customer_receipt_settlements',
        'inv_supplier_payment_settlements',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'bank_id')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->unsignedBigInteger('bank_id')->nullable()->after('bank_name')->index();
                });
            }

            // Backfill by exact name match. Verified against production data:
            // all 25 settlements carrying a bank name match a master bank, so
            // this leaves no unresolved rows and guesses at nothing. Rows with
            // no bank (cash) correctly stay NULL.
            DB::table($table)
                ->whereNull('bank_id')
                ->whereNotNull('bank_name')
                ->where('bank_name', '<>', '')
                ->update([
                    'bank_id' => DB::raw(
                        '(SELECT b.id FROM core_banks b WHERE b.bank_name = ' . $table . '.bank_name LIMIT 1)'
                    ),
                ]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'bank_id')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dropIndex(['bank_id']);
                    $t->dropColumn('bank_id');
                });
            }
        }
    }
};
