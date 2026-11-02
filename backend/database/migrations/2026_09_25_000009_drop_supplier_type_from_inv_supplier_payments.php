<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the duplicated supplier group from supplier payments.
 *
 * inv_supplier_payments.supplier_type held a copy of the supplier's group as
 * free text. It drove no posting and was never filtered or searched — purely
 * descriptive — while the payment already stores supplier_id, so the group is
 * always one join away. Keeping the copy only created two versions that could
 * disagree, and made "filter by group" break silently whenever a group was
 * renamed. The group is now read live through supplier_id.
 *
 * Snapshotting belongs on values that drive an accounting posting (the ledger
 * account and amounts on a GL line), not on descriptive partner attributes.
 *
 * No data is lost: each payment's old value is copied to
 * core_archived_column_values first, and down() restores it.
 */
return new class extends Migration
{
    private const TABLE  = 'inv_supplier_payments';
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
