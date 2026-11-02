<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the legacy free-text supplier_type column, now replaced by
 * supplier_group_id.
 *
 * No data is lost: every non-empty value is copied to
 * core_archived_column_values first, and down() restores the column
 * with its original values. On live the values were "Trade" / "Service",
 * entered inconsistently (overseas fabric suppliers marked "Service"), which
 * is why the backfill places every supplier in Trade (Local) for staff to
 * re-assign rather than trusting them.
 *
 * Isolated in its own migration so it can be skipped independently if a target
 * environment runs SQLite < 3.35 (dropColumn support).
 */
return new class extends Migration
{
    private const TABLE  = 'inv_supplier_masters';
    private const COLUMN = 'supplier_type';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        DB::transaction(function (): void {
            DB::table(self::TABLE)
                ->whereNotNull(self::COLUMN)
                ->where(self::COLUMN, '<>', '')
                ->orderBy('id')
                ->get(['id', self::COLUMN])
                ->each(static function (object $row): void {
                    DB::table('core_archived_column_values')->updateOrInsert(
                        ['table_name' => self::TABLE, 'column_name' => self::COLUMN, 'record_id' => $row->id],
                        ['value' => $row->{self::COLUMN}, 'archived_at' => now()],
                    );
                });
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string(self::COLUMN, 50)->nullable()->after('reference_no');
            });
        }

        if (! Schema::hasTable('core_archived_column_values')) {
            return;
        }

        $archived = DB::table('core_archived_column_values')
            ->where('table_name', self::TABLE)
            ->where('column_name', self::COLUMN);

        $archived->get(['record_id', 'value'])->each(static function (object $row): void {
            DB::table(self::TABLE)->where('id', $row->record_id)->update([self::COLUMN => $row->value]);
        });

        $archived->delete();
    }
};
