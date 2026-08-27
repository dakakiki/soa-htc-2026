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
            ContentLookupSeeder::class,
        ]);

        /*
         * The public site's own structure - sections, menus, the cookie policy
         * page - belongs on every install, production included: without it a
         * fresh deploy comes up with an empty front page and no navigation.
         *
         * Skipped under `testing`, and skipped HERE rather than inside the
         * seeder. Most layout tests build the one block they are about and read
         * `data.blocks.0`, so a pre-filled zone would put a seeded hero in front
         * of them; and the singleton types would refuse the second instance
         * outright. Deciding it here keeps WebsiteSeeder callable from the test
         * that does want a whole site to look at (WebsiteSeederTest).
         */
        if (! app()->environment('testing')) {
            $this->call(WebsiteSeeder::class);
        }

        $this->call(MasterDataSeeder::class);
    }
}
