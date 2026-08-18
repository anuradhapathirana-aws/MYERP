<?php

declare(strict_types=1);

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\CostingExpenseType;

class CostingExpenseTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // FOB expenses ("/SSCL" is part of the duty charge's NAME, not a tax)
            ['name' => 'Freight Charges',            'costing_type' => 'fob', 'sort_order' => 1],
            ['name' => 'Custom Duty/SSCL',           'costing_type' => 'fob', 'sort_order' => 2],
            ['name' => 'Bank Charges',               'costing_type' => 'fob', 'sort_order' => 3],
            ['name' => 'Bank Commission',            'costing_type' => 'fob', 'sort_order' => 4],
            ['name' => 'Clearance & Transportation', 'costing_type' => 'fob', 'sort_order' => 5],
            ['name' => 'Insurance',                  'costing_type' => 'fob', 'sort_order' => 6],

            // CIF expenses
            ['name' => 'Import Customs Duty/SSCL',   'costing_type' => 'cif', 'sort_order' => 1],
            ['name' => 'Clearance & Transportation', 'costing_type' => 'cif', 'sort_order' => 2],
            ['name' => 'Bank Charges',               'costing_type' => 'cif', 'sort_order' => 3],
            ['name' => 'Bank Commission',            'costing_type' => 'cif', 'sort_order' => 4],
        ];

        // Remove stale entries not in the new canonical list
        $keep = collect($types)->map(fn ($t) => $t['name'] . '|' . $t['costing_type'])->all();
        CostingExpenseType::all()->each(function (CostingExpenseType $row) use ($keep): void {
            if (!in_array($row->name . '|' . $row->costing_type->value, $keep, true)) {
                $row->delete();
            }
        });

        foreach ($types as $type) {
            CostingExpenseType::updateOrCreate(
                ['name' => $type['name'], 'costing_type' => $type['costing_type']],
                ['is_active' => true, 'sort_order' => $type['sort_order']],
            );
        }
    }
}
