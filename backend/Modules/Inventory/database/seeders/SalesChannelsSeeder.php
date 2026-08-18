<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalesChannelsSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            [
                'type'              => 'Wholesale',
                'sales_channel_name'=> 'Bulk Wholesale',
                'max_qty'           => 50000.0000,
                'applicable_from'   => '2024-01-01',
                'applicable_to'     => '2026-12-31',
                'description'       => 'Large volume orders for garment manufacturers and export buyers',
                'status'            => 'Active',
            ],
            [
                'type'              => 'Retail',
                'sales_channel_name'=> 'Walk-In Retail',
                'max_qty'           => 500.0000,
                'applicable_from'   => '2024-01-01',
                'applicable_to'     => '2026-12-31',
                'description'       => 'Over-the-counter retail sales at showroom',
                'status'            => 'Active',
            ],
            [
                'type'              => 'e-commerce',
                'sales_channel_name'=> 'Online Store',
                'max_qty'           => 2000.0000,
                'applicable_from'   => '2024-01-01',
                'applicable_to'     => '2026-12-31',
                'description'       => 'Sales through company website and online marketplaces',
                'status'            => 'Active',
            ],
            [
                'type'              => 'Wholesale',
                'sales_channel_name'=> 'Export Channel',
                'max_qty'           => 100000.0000,
                'applicable_from'   => '2024-01-01',
                'applicable_to'     => '2026-12-31',
                'description'       => 'International export to fashion brands and distributors',
                'status'            => 'Active',
            ],
            [
                'type'              => 'Retail',
                'sales_channel_name'=> 'Dealer Network',
                'max_qty'           => 5000.0000,
                'applicable_from'   => '2024-01-01',
                'applicable_to'     => '2026-12-31',
                'description'       => 'Sales through registered local dealers and agents',
                'status'            => 'Active',
            ],
        ];

        foreach ($channels as $channel) {
            DB::table('inv_sales_channels')->updateOrInsert(
                ['sales_channel_name' => $channel['sales_channel_name']],
                array_merge($channel, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
