<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentMode;

class PaymentModeSeeder extends Seeder
{
    public function run(): void
    {
        $modes = [
            ['payment_mode_name' => 'Cash',   'code' => 'cash',   'type' => 'cash', 'requires_bank_details' => false, 'requires_reference_no' => false, 'requires_date' => false, 'sort_order' => 1],
            ['payment_mode_name' => 'Cheque', 'code' => 'cheque', 'type' => 'cheque', 'requires_bank_details' => true,  'requires_reference_no' => true,  'requires_date' => true,  'sort_order' => 2],
            ['payment_mode_name' => 'Card',   'code' => 'card',   'type' => 'card', 'requires_bank_details' => true,  'requires_reference_no' => true,  'requires_date' => false, 'sort_order' => 3],
            ['payment_mode_name' => 'Setoff', 'code' => 'setoff', 'type' => 'setoff', 'requires_bank_details' => false, 'requires_reference_no' => false, 'requires_date' => false, 'sort_order' => 4],
            ['payment_mode_name' => 'Online Transfer', 'code' => 'online_transfer', 'type' => 'bank_transfer', 'requires_bank_details' => true, 'requires_reference_no' => true, 'requires_date' => true, 'sort_order' => 5],
        ];

        foreach ($modes as $mode) {
            // Existence is checked INCLUDING soft-deleted rows, but a found row
            // is never written to. Three separate reasons, all of which the
            // previous updateOrCreate got wrong:
            //
            //   - a client who deleted "Card" because they do not accept cards
            //     had it resurrected on every deployment
            //   - a renamed mode had its name reset
            //   - code AND payment_mode_name are both UNIQUE, so inserting
            //     over a soft-deleted row fails the index and aborts the
            //     deploy outright
            //
            // These five are starting defaults, not fixed product data: the
            // Payment Modes screen exists precisely so a client can change
            // them, and a deploy must not undo that.
            $exists = PaymentMode::withTrashed()
                ->where('code', $mode['code'])
                ->orWhere('payment_mode_name', $mode['payment_mode_name'])
                ->exists();

            if (! $exists) {
                PaymentMode::create($mode);
            }
        }
    }
}
