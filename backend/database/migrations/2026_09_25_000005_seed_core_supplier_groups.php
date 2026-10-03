<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the standard supplier groups as part of the schema, not the seeders,
 * because the backfill migration that follows depends on TRADE_LOCAL existing.
 *
 * Deliberately uses raw DB calls and literal rows rather than importing
 * SupplierGroup / SupplierGroupSeeder: a migration must keep working even if
 * those classes are later renamed or removed.
 */
return new class extends Migration
{
    /** @var list<array{code:string,name:string,description:string}> */
    private const GROUPS = [
        ['code' => 'TRADE_LOCAL',       'name' => 'Trade (Local)',        'description' => 'Local suppliers of stock items'],
        ['code' => 'IMPORT_FOREIGN',    'name' => 'Import / Foreign',     'description' => 'Overseas suppliers — separate payable account, duty and FX'],
        ['code' => 'SERVICE',           'name' => 'Service Providers',    'description' => 'Non-stock services such as repairs, consulting and cleaning'],
        ['code' => 'UTILITY',           'name' => 'Utility Providers',    'description' => 'Electricity, water, telecom and similar recurring bills'],
        ['code' => 'CAPITAL_ASSET',     'name' => 'Capital / Asset',      'description' => 'Fixed assets and machinery — posts to assets, not expense'],
        ['code' => 'TRANSPORT_FREIGHT', 'name' => 'Transport & Freight',  'description' => 'Carriers and forwarders; often allocated to landed cost'],
        ['code' => 'SUBCONTRACTOR',     'name' => 'Subcontractors',       'description' => 'Outsourced processing'],
        ['code' => 'ONE_TIME',          'name' => 'One-Time Vendors',     'description' => 'Ad-hoc suppliers with no repeat business'],
        ['code' => 'EMPLOYEE_VENDOR',   'name' => 'Employee Vendors',     'description' => 'Staff advances and reimbursements'],
        ['code' => 'STATUTORY',         'name' => 'Statutory / Government', 'description' => 'Tax authorities and regulators'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::GROUPS as $i => $group) {
            DB::table('core_supplier_groups')->updateOrInsert(
                ['code' => $group['code']],
                [
                    'name'        => $group['name'],
                    'description' => $group['description'],
                    'is_active'   => true,
                    'sort_order'  => $i + 1,
                    'updated_at'  => $now,
                    'created_at'  => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('core_supplier_groups')
            ->whereIn('code', array_column(self::GROUPS, 'code'))
            ->delete();
    }
};
