<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CuestionarioSeeder::class);
        $this->call(VitiPlatformSeeder::class);
        $this->call(PaymentQrSeeder::class);
    }
}
