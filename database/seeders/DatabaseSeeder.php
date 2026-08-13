<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            // Reference data first (runs everywhere), then dev-only synthetic data.
            RolePermissionSeeder::class,
            MasterDataSeeder::class,
        ]);
    }
}
