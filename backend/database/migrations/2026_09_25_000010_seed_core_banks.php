<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the licensed commercial banks of Sri Lanka with their CBSL bank codes.
 *
 * This is REFERENCE DATA, not sample data, so it ships as a migration rather
 * than a seeder: `php artisan migrate` then delivers it to every environment
 * automatically, with no seeder step for anyone to forget on deployment day.
 * Same reasoning as the supplier groups in 2026_09_25_000005.
 *
 * Deliberately no branches: branch lists differ per client and a wrong one is
 * worse than an empty one. Clients add the branches they actually use through
 * the Bank Branches screen.
 *
 * Idempotent via updateOrInsert on bank_code, so re-running is harmless and a
 * client's edits to bank_name are the only thing that gets reset.
 */
return new class extends Migration
{
    /** @var list<array{code:string,name:string}> */
    private const BANKS = [
        ['code' => '7010', 'name' => 'Bank of Ceylon'],
        ['code' => '7038', 'name' => "People's Bank"],
        ['code' => '7047', 'name' => 'Hatton National Bank PLC'],
        ['code' => '7056', 'name' => 'Commercial Bank of Ceylon PLC'],
        ['code' => '7083', 'name' => 'Sampath Bank PLC'],
        ['code' => '7092', 'name' => 'Seylan Bank PLC'],
        ['code' => '7108', 'name' => 'Union Bank of Colombo PLC'],
        ['code' => '7135', 'name' => 'Nations Trust Bank PLC'],
        ['code' => '7162', 'name' => 'DFCC Bank PLC'],
        ['code' => '7205', 'name' => 'Pan Asia Banking Corporation PLC'],
        ['code' => '7214', 'name' => 'National Development Bank PLC'],
        ['code' => '7278', 'name' => 'Amana Bank PLC'],
        ['code' => '7302', 'name' => 'Cargills Bank PLC'],
        ['code' => '7454', 'name' => 'Standard Chartered Bank'],
        ['code' => '7472', 'name' => 'Citibank N.A.'],
        ['code' => '7481', 'name' => 'HSBC'],
        ['code' => '7719', 'name' => 'Regional Development Bank'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::BANKS as $bank) {
            DB::table('core_banks')->updateOrInsert(
                ['bank_code' => $bank['code']],
                [
                    'bank_name'  => $bank['name'],
                    'is_active'  => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('core_banks')
            ->whereIn('bank_code', array_column(self::BANKS, 'code'))
            ->delete();
    }
};
