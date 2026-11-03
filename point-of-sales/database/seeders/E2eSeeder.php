<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Data for the Playwright end-to-end suite (e2e/): the same company warehouses, products and
 * opening stock as production, with throw-away credentials passed in by e2e/env.js
 * (WMS_SUPERADMIN_PASSWORD, WMS_POS_PINS). Never run against a real database.
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment('e2e'), 500, 'E2eSeeder only runs with APP_ENV=e2e.');

        $this->call([
            CompanyUserSeeder::class,
            CompanyProductSeeder::class,
            CompanyStockRmSeeder::class,
        ]);
    }
}
