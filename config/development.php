<?php

return [
    /*
     * The password `MasterDataSeeder` gives the development administrator
     * (`admin@soahtc.test`). Unset, the seeder invents one and prints it once.
     *
     * Deliberately absent from `.env.example`: that file is what a deployment
     * copies, and the entire point of this setting is that a deployment cannot
     * have it. Put `DEV_ADMIN_PASSWORD=…` in your own `.env`, which is not in
     * the repository, if you would rather type the same password every time.
     */
    'admin_password' => env('DEV_ADMIN_PASSWORD'),
];
