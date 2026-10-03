<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * SAMPLE DATA — NEVER RUN THIS ON A LIVE SERVER.
 *
 * Inserts fictitious industries, companies, locations, employees and suppliers
 * so a fresh development machine has something to click through. On a client
 * database this would inject fake records ("Coats Thread Lanka", "Jinxing
 * Textile", invented employees) alongside their real data.
 *
 * It is deliberately NOT called from DatabaseSeeder — it must be requested by
 * name, and it refuses to run outside a local/testing environment:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Reference data belongs in migrations (supplier groups, banks) and production
 * configuration belongs in DatabaseSeeder. This file is only for demo records.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Belt and braces: even if someone calls this by name on a server, the
        // environment check stops it. Fail loudly rather than quietly polluting
        // a client's database.
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException(
                'DemoDataSeeder inserts fictitious records and must never run outside '
                . 'local/testing. Current environment: ' . app()->environment() . '. '
                . 'If you need reference data on a server, use migrations instead.'
            );
        }

        // Order matters: Companies needs Industries, Locations needs Companies,
        // Employees needs Locations, and Suppliers needs the supplier groups
        // created by the 2026_09_25_000005 migration.
        $this->call([
            IndustriesSeeder::class,
            CompaniesSeeder::class,
            LocationsSeeder::class,
            EmployeeSeeder::class,
            SuppliersSeeder::class,
        ]);
    }
}
