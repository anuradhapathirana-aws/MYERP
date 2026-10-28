<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switches the Finance module OFF on installations that have never used it.
 *
 * Early releases seeded module.finance = true on every database, long before
 * Finance existed. On such a client nothing happened while the code was
 * absent -- but the release that ships Finance would wake it up at once:
 * every Customer Receipt and Supplier Payment would start demanding a Cash
 * Book / Bank ledger account that nobody has created, and staff would be
 * blocked from confirming them on deployment day.
 *
 * An entitlement is a deliberate decision per client (super_admin, Settings),
 * so the safe reading of "on, but never touched" is "not set up yet". It runs
 * before the deploy's Finance seeders, so "never used" means: no ledger
 * account and no journal exists. Any installation that has really used
 * Finance (QA, a client already set up) keeps its setting untouched.
 *
 * down() is a no-op: the previous value was an accident, not a decision, and
 * a super_admin re-enables Finance from Settings when the client is ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        $setting = DB::table('global_settings')->where('key', 'module.finance')->first(['id', 'value']);

        if ($setting === null || json_decode((string) $setting->value) !== true) {
            return;
        }

        foreach (['fin_ledger_accounts', 'fin_journal_entries'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return;
            }
        }

        DB::table('global_settings')->where('id', $setting->id)->update([
            'value'      => json_encode(false),
            'updated_at' => now(),
        ]);

        // Settings are cached forever; only SettingsService invalidates them.
        app(SettingsService::class)->forget('module.finance');
    }

    public function down(): void
    {
        // Intentionally empty -- see the class comment.
    }
};
