<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The built-in purchase tax codes, plus the supplier-side SSCL rate they use.
 *
 * SSCL_PUR is kept apart from SSCL: SSCL is OUR levy on our own sales (1.25 %
 * effective for a trader, switched on/off per client); SSCL_PUR is what a
 * supplier adds to their invoice (2.5 %), which we pay whatever our own status.
 *
 * Idempotent: an existing tax or code is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (! DB::table('core_taxes')->where('code', 'SSCL_PUR')->exists()) {
            DB::table('core_taxes')->insert([
                'code'           => 'SSCL_PUR',
                'name'           => 'SSCL (charged by suppliers)',
                'tax_type'       => 'sscl',
                'rate'           => 2.50,
                'effective_from' => '2022-10-01',
                'is_active'      => true,
                'is_system'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            Cache::forget('core_taxes.rows');
        }

        // [code, name, vat_category, components: [tax_code_ref, is_compound, is_recoverable]]
        $codes = [
            ['VAT-IN',      'VAT (Tax Invoice)',              'standard',     [['VAT', false, true]]],
            ['SSCL-VAT-IN', 'SSCL + VAT (Tax Invoice)',       'standard',     [['SSCL_PUR', false, false], ['VAT', true, true]]],
            ['NO-TAX-INV',  'No tax / No Tax Invoice',        'out_of_scope', []],
            ['IMPORT',      'Import (taxes paid at Customs)', 'import',       []],
            ['EXEMPT-IN',   'Exempt goods',                   'exempt',       []],
        ];

        foreach ($codes as $sort => [$code, $name, $category, $components]) {
            if (DB::table('core_tax_codes')->where('code', $code)->exists()) {
                continue;
            }

            $id = DB::table('core_tax_codes')->insertGetId([
                'code'         => $code,
                'name'         => $name,
                'scope'        => 'purchase',
                'vat_category' => $category,
                'is_active'    => true,
                'is_system'    => true,
                'sort_order'   => $sort + 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            foreach ($components as $i => [$ref, $compound, $recoverable]) {
                DB::table('core_tax_code_components')->insert([
                    'tax_code_id'    => $id,
                    'tax_code_ref'   => $ref,
                    'sequence'       => $i + 1,
                    'is_compound'    => $compound,
                    'is_recoverable' => $recoverable,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // The previous migration's down() drops the tables; SSCL_PUR may be in use.
    }
};
