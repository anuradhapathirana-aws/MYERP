<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Industries, Companies, Locations and Suppliers moved to core in
        // Step 1 and are now demo data, seeded by Database\Seeders\DemoDataSeeder.
        // Several seeders below depend on them, so fail loudly rather than
        // inserting rows with dangling location/company ids.
        if (DB::table('inv_locations')->doesntExist()) {
            throw new \RuntimeException(
                'Shared demo master data is missing. Run '
                . '`php artisan db:seed --class=DemoDataSeeder` first — Industries, '
                . 'Companies, Locations and Suppliers moved to core in Step 1.'
            );
        }

        $this->call([
            CostingExpenseTypeSeeder::class,

            // ── Step 1: no dependencies ──────────────────────────────────────
            StockReferenceTypesSeeder::class,
            UnitCategoriesSeeder::class,
            StoreTypesSeeder::class,
            SalesChannelsSeeder::class,

            // ── Step 2: depend on Step 1 ─────────────────────────────────────
            UnitTypesSeeder::class,      // needs UnitCategories

            // ── Step 3: depend on core master data ───────────────────────────
            CategoriesSeeder::class,     // needs Companies, Industries (core)

            // ── Step 4: depend on Step 3 ─────────────────────────────────────
            StoresSeeder::class,         // needs StoreTypes, Locations (core)
            AttributeTypesSeeder::class, // needs Categories

            // ── Step 5: depend on Step 4 ─────────────────────────────────────
            AttributesSeeder::class,     // needs AttributeTypes

            // ── Step 6: independent master data ──────────────────────────────
            CustomersSeeder::class,

            // ── Step 7: depends on all above ─────────────────────────────────
            ProductsSeeder::class,       // needs Suppliers (core), Locations (core)
        ]);
    }
}
