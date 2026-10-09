<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Align the bank master's display names with the names the application has
 * actually been recording.
 *
 * 2026_09_25_000010 seeded official entity names ("Hatton National Bank PLC"),
 * but every bank_name stored in inv_customer_receipt_settlements uses the short
 * form the payment forms have always offered:
 *
 *     Hatton National Bank  x17
 *     Bank of Ceylon        x5
 *     Commercial Bank       x3
 *
 * Once the payment forms select from this master, any stored value that is not
 * an option would render as a blank dropdown — and saving would silently
 * rewrite the bank on a posted receipt. Matching the short form keeps every
 * historical value selectable and needs no data migration.
 *
 * The CBSL bank_code still carries each bank's formal identity, so nothing is
 * lost by shortening the display name.
 *
 * Delivered as a follow-up migration rather than an edit to 000010, because
 * that migration has already run on development databases and an edited
 * migration never re-runs — which would leave those databases on the old names
 * forever.
 */
return new class extends Migration
{
    /** @var array<string, array{0:string,1:string}> bank_code => [new name, old name] */
    private const RENAMES = [
        '7047' => ['Hatton National Bank', 'Hatton National Bank PLC'],
        '7056' => ['Commercial Bank',      'Commercial Bank of Ceylon PLC'],
        '7083' => ['Sampath Bank',         'Sampath Bank PLC'],
        '7092' => ['Seylan Bank',          'Seylan Bank PLC'],
        '7108' => ['Union Bank',           'Union Bank of Colombo PLC'],
        '7135' => ['Nations Trust Bank',   'Nations Trust Bank PLC'],
        '7162' => ['DFCC Bank',            'DFCC Bank PLC'],
        '7205' => ['Pan Asia Bank',        'Pan Asia Banking Corporation PLC'],
        '7214' => ['NDB Bank',             'National Development Bank PLC'],
        '7278' => ['Amana Bank',           'Amana Bank PLC'],
        '7302' => ['Cargills Bank',        'Cargills Bank PLC'],
        '7472' => ['Citibank',             'Citibank N.A.'],
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $code => [$new, $_old]) {
            DB::table('core_banks')->where('bank_code', $code)->update([
                'bank_name'  => $new,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $code => [$_new, $old]) {
            DB::table('core_banks')->where('bank_code', $code)->update([
                'bank_name'  => $old,
                'updated_at' => now(),
            ]);
        }
    }
};
