<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\PaymentMode;

class PaymentModeSeeder extends Seeder
{
    public function run(): void
    {
        $modes = [
            ['payment_mode_name' => 'Cash',   'code' => 'cash',   'requires_bank_details' => false, 'requires_reference_no' => false, 'requires_date' => false, 'sort_order' => 1],
            ['payment_mode_name' => 'Cheque', 'code' => 'cheque', 'requires_bank_details' => true,  'requires_reference_no' => true,  'requires_date' => true,  'sort_order' => 2],
            ['payment_mode_name' => 'Card',   'code' => 'card',   'requires_bank_details' => true,  'requires_reference_no' => true,  'requires_date' => false, 'sort_order' => 3],
            ['payment_mode_name' => 'Setoff', 'code' => 'setoff', 'requires_bank_details' => false, 'requires_reference_no' => false, 'requires_date' => false, 'sort_order' => 4],
        ];

        foreach ($modes as $mode) {
            PaymentMode::withTrashed()->updateOrCreate(['code' => $mode['code']], $mode);
        }
    }
}
