<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;

class InventoryDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CostingExpenseTypeSeeder::class,

            // ── Step 1: no dependencies ──────────────────────────────────────
            StockReferenceTypesSeeder::class,
            IndustriesSeeder::class,
            UnitCategoriesSeeder::class,
            StoreTypesSeeder::class,
            SalesChannelsSeeder::class,

            // ── Step 2: depend on Step 1 ─────────────────────────────────────
            CompaniesSeeder::class,      // needs Industries
            UnitTypesSeeder::class,      // needs UnitCategories

            // ── Step 3: depend on Step 2 ─────────────────────────────────────
            LocationsSeeder::class,      // needs Companies, Industries
            CategoriesSeeder::class,     // needs Companies, Industries

            // ── Step 4: depend on Step 3 ─────────────────────────────────────
            StoresSeeder::class,         // needs StoreTypes, Locations
            AttributeTypesSeeder::class, // needs Categories

            // ── Step 5: depend on Step 4 ─────────────────────────────────────
            AttributesSeeder::class,     // needs AttributeTypes

            // ── Step 6: independent master data ──────────────────────────────
            CustomersSeeder::class,
            SuppliersSeeder::class,

            // ── Step 7: depends on all above ─────────────────────────────────
            ProductsSeeder::class,
        ]);
    }
}
