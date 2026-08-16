<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Lookup / System Data
        |--------------------------------------------------------------------------
        */

        $this->call([
            RoleSeeder::class,
            DocumentStatusSeeder::class,
            DocumentTypeSeeder::class,
            PrioritySeeder::class,
            ConfidentialityLevelSeeder::class,
            RouteActionSeeder::class,
        ]);
    }
}