<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every payment mode a fixed type (Cash, Cheque, Bank Transfer, Card,
 * Set-off) so rules can follow the type instead of the free-text code.
 *
 * Additive only: a new column, filled from each row's existing code --
 * soft-deleted rows included, so restoring one never leaves it typeless.
 * No existing value is changed. Unknown codes become Bank Transfer. The
 * mapping is inlined (it mirrors PaymentModeType::fromLegacyCode) so this
 * migration never changes meaning if the enum does.
 *
 * The table is created by the Inventory module's migrations; this core
 * migration sorts after it by filename, so it always runs second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_payment_modes', function (Blueprint $table): void {
            $table->string('type', 20)->nullable()->after('code')->index();
        });

        DB::table('inv_payment_modes')->orderBy('id')->get(['id', 'code'])
            ->each(static function (object $row): void {
                DB::table('inv_payment_modes')->where('id', $row->id)->update([
                    'type' => match (strtolower(trim((string) $row->code))) {
                        'cash', 'petty_cash' => 'cash',
                        'cheque'             => 'cheque',
                        'card'               => 'card',
                        'setoff'             => 'setoff',
                        default              => 'bank_transfer',
                    },
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('inv_payment_modes', function (Blueprint $table): void {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
