<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The three built-in taxes every Sri Lankan install starts with.
 *
 *   VAT    18 %   — the standard rate.
 *   SSCL   1.25 % — 2.5 % levied on 50 % of a trader's turnover. Carried over
 *                   from the old Finance "SSCL rate" setting (tax.sscl_rate)
 *                   when one was set; otherwise seeded SWITCHED OFF, so an
 *                   install that never charged SSCL keeps posting exactly as
 *                   before until an administrator turns it on.
 *   EXEMPT 0 %.
 *
 * Idempotent: a code that already has a row is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $oldSscl = DB::table('global_settings')->where('key', 'tax.sscl_rate')->value('value');
        $oldSscl = $oldSscl !== null ? (float) json_decode((string) $oldSscl, true) : 0.0;

        $rows = [
            ['code' => 'VAT',    'name' => 'Value Added Tax',                  'tax_type' => 'vat',   'rate' => 18.00, 'effective_from' => '2024-01-01', 'is_active' => true],
            ['code' => 'SSCL',   'name' => 'Social Security Contribution Levy', 'tax_type' => 'sscl',  'rate' => $oldSscl > 0 ? round($oldSscl, 2) : 1.25, 'effective_from' => '2022-10-01', 'is_active' => $oldSscl > 0],
            ['code' => 'EXEMPT', 'name' => 'VAT Exempt',                       'tax_type' => 'other', 'rate' => 0.00,  'effective_from' => '2022-01-01', 'is_active' => true],
        ];

        foreach ($rows as $row) {
            if (DB::table('core_taxes')->where('code', $row['code'])->exists()) {
                continue;
            }

            DB::table('core_taxes')->insert($row + ['is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        Cache::forget('core_taxes.rows');
    }

    public function down(): void
    {
        // Rows may have been edited or extended since; the table drop in the
        // previous migration's down() removes them.
    }
};
