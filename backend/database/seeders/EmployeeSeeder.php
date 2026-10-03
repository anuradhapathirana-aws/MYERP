<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A handful of sample employees so petty cash custodian / requestor / payee
 * dropdowns are not empty on a fresh install. Attached to the first location
 * when one exists.
 */
class EmployeeSeeder extends Seeder
{
    /** @var list<array{code:string,name:string,designation:string,department:string}> */
    private const EMPLOYEES = [
        ['code' => 'EMP-0001', 'name' => 'Nimal Perera',      'designation' => 'Accountant',          'department' => 'Finance'],
        ['code' => 'EMP-0002', 'name' => 'Kumari Silva',      'designation' => 'Accounts Executive',  'department' => 'Finance'],
        ['code' => 'EMP-0003', 'name' => 'Ashan Fernando',    'designation' => 'Stores Keeper',       'department' => 'Stores'],
        ['code' => 'EMP-0004', 'name' => 'Dilani Jayasuriya', 'designation' => 'Admin Officer',       'department' => 'Administration'],
        ['code' => 'EMP-0005', 'name' => 'Ruwan Bandara',     'designation' => 'Procurement Officer', 'department' => 'Procurement'],
    ];

    public function run(): void
    {
        $now = now();

        // Soft link — the location is optional, so a missing one is not fatal.
        $locationId = DB::table('inv_locations')->orderBy('id')->value('id');

        foreach (self::EMPLOYEES as $employee) {
            DB::table('core_employees')->updateOrInsert(
                ['employee_code' => $employee['code']],
                [
                    'employee_name' => $employee['name'],
                    'designation'   => $employee['designation'],
                    'department'    => $employee['department'],
                    'location_id'   => $locationId,
                    'is_active'     => true,
                    'updated_at'    => $now,
                    'created_at'    => $now,
                ],
            );
        }
    }
}
