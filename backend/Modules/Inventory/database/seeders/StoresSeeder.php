<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StoresSeeder extends Seeder
{
    public function run(): void
    {
        $typeId  = fn(string $name) => DB::table('inv_store_types')->where('store_type_name', $name)->value('id');
        $locId   = fn(string $code) => DB::table('inv_locations')->where('location_code', $code)->value('id');

        $factoryId   = $locId('LOC-FAC-001');
        $warehouseId = $locId('LOC-WH-001');
        $showroomId  = $locId('LOC-SHW-001');

        $stores = [
            // Factory stores
            [
                'store_type_id' => $typeId('Raw Material Store'),
                'location_id'   => $factoryId,
                'store_code'    => 'STR-RM-001',
                'store_name'    => 'Factory Raw Material Store',
                'uom'           => 'kg',
                'capacity'      => 50000.0000,
                'address_line_1'=> 'Plot 22, EPZ North',
                'city'          => 'Katunayake',
                'state'         => 'Western Province',
                'country'       => 'Sri Lanka',
                'postal_code'   => '11450',
                'manager_name'  => 'Suresh Perera',
                'phone'         => '+94 11 2345691',
                'email'         => 'rm.store@silkroute.lk',
                'description'   => 'Stores grey fabric rolls, raw cotton, and yarn cones',
                'is_active'     => true,
            ],
            [
                'store_type_id' => $typeId('Work-In-Progress Store'),
                'location_id'   => $factoryId,
                'store_code'    => 'STR-WIP-001',
                'store_name'    => 'Factory WIP Store',
                'uom'           => 'm',
                'capacity'      => 20000.0000,
                'address_line_1'=> 'Plot 22, EPZ North – Block B',
                'city'          => 'Katunayake',
                'state'         => 'Western Province',
                'country'       => 'Sri Lanka',
                'postal_code'   => '11450',
                'manager_name'  => 'Kamani Silva',
                'phone'         => '+94 11 2345692',
                'email'         => 'wip.store@silkroute.lk',
                'description'   => 'Intermediate storage between dyeing and finishing lines',
                'is_active'     => true,
            ],
            [
                'store_type_id' => $typeId('Chemical & Dye Store'),
                'location_id'   => $factoryId,
                'store_code'    => 'STR-CHM-001',
                'store_name'    => 'Chemical & Dye Store',
                'uom'           => 'kg',
                'capacity'      => 10000.0000,
                'address_line_1'=> 'Plot 22, EPZ North – Chemical Block',
                'city'          => 'Katunayake',
                'state'         => 'Western Province',
                'country'       => 'Sri Lanka',
                'postal_code'   => '11450',
                'manager_name'  => 'Nalaka Fernando',
                'phone'         => '+94 11 2345693',
                'email'         => 'chem.store@silkroute.lk',
                'description'   => 'Secure store for dyes, chemicals, and auxiliary agents',
                'is_active'     => true,
            ],
            [
                'store_type_id' => $typeId('Rejected Goods Store'),
                'location_id'   => $factoryId,
                'store_code'    => 'STR-REJ-001',
                'store_name'    => 'Quality Rejection Hold Store',
                'uom'           => 'm',
                'capacity'      => 5000.0000,
                'address_line_1'=> 'Plot 22, EPZ North – QC Zone',
                'city'          => 'Katunayake',
                'state'         => 'Western Province',
                'country'       => 'Sri Lanka',
                'postal_code'   => '11450',
                'manager_name'  => 'Priya Jayasinghe',
                'phone'         => '+94 11 2345694',
                'email'         => 'qc.store@silkroute.lk',
                'description'   => 'Holds fabric failing QC pending re-processing or write-off',
                'is_active'     => true,
            ],
            // Warehouse stores
            [
                'store_type_id' => $typeId('Finished Goods Store'),
                'location_id'   => $warehouseId,
                'store_code'    => 'STR-FG-001',
                'store_name'    => 'Finished Goods Warehouse – Bay A',
                'uom'           => 'm',
                'capacity'      => 100000.0000,
                'address_line_1'=> 'No. 88, Nattandiya Road – Bay A',
                'city'          => 'Peliyagoda',
                'state'         => 'Western Province',
                'country'       => 'Sri Lanka',
                'postal_code'   => '11300',
                'manager_name'  => 'Roshan Wickramasinghe',
                'phone'         => '+94 11 2345701',
                'email'         => 'fg.store@silkroute.lk',
                'description'   => 'Finished and packed fabric rolls awaiting dispatch',
                'is_active'     => true,
            ],
            [
                'store_type_id' => $typeId('Packing Material Store'),
                'location_id'   => $warehouseId,
                'store_code'    => 'STR-PKG-001',
                'store_name'    => 'Packing Material Store',
                'uom'           => 'pcs',
                'capacity'      => 50000.0000,
                'address_line_1'=> 'No. 88, Nattandiya Road – Bay B',
                'city'          => 'Peliyagoda',
                'state'         => 'Western Province',
                'country'       => 'Sri Lanka',
                'postal_code'   => '11300',
                'manager_name'  => 'Anoma Rathnayake',
                'phone'         => '+94 11 2345702',
                'email'         => 'pkg.store@silkroute.lk',
                'description'   => 'Polybags, cartons, labels, and packing accessories',
                'is_active'     => true,
            ],
            [
                'store_type_id' => $typeId('Spare Parts Store'),
                'location_id'   => $warehouseId,
                'store_code'    => 'STR-SPR-001',
                'store_name'    => 'Spare Parts & Maintenance Store',
                'uom'           => 'pcs',
                'capacity'      => 5000.0000,
                'address_line_1'=> 'No. 88, Nattandiya Road – Mezzanine',
                'city'          => 'Peliyagoda',
                'state'         => 'Western Province',
                'country'       => 'Sri Lanka',
                'postal_code'   => '11300',
                'manager_name'  => 'Chaminda Bandara',
                'phone'         => '+94 11 2345703',
                'email'         => 'spare.store@silkroute.lk',
                'description'   => 'Loom spare parts, motor belts, and maintenance consumables',
                'is_active'     => true,
            ],
            // Showroom
            [
                'store_type_id' => $typeId('Showroom'),
                'location_id'   => $showroomId,
                'store_code'    => 'STR-SHW-001',
                'store_name'    => 'Colombo Showroom Display Stock',
                'uom'           => 'm',
                'capacity'      => 2000.0000,
                'address_line_1'=> 'No. 5, Galle Road',
                'city'          => 'Colombo',
                'state'         => 'Western Province',
                'country'       => 'Sri Lanka',
                'postal_code'   => '00300',
                'manager_name'  => 'Dilini Seneviratne',
                'phone'         => '+94 11 2345721',
                'email'         => 'showroom.store@silkroute.lk',
                'description'   => 'Display fabrics and retail-counter samples',
                'is_active'     => true,
            ],
        ];

        foreach ($stores as $store) {
            DB::table('inv_stores')->updateOrInsert(
                ['store_code' => $store['store_code']],
                array_merge($store, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
