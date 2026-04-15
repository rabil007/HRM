<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PermissionsSeeder::class);
        $this->call(CurrencySeeder::class);
        $this->call(CountrySeeder::class);
        $this->call(VisaTypesSeeder::class);
        $this->call(GendersSeeder::class);
        $this->call(ReligionsSeeder::class);
        $this->call(BanksSeeder::class);
        $this->call(CompanySeeder::class);
        $this->call(AdminSeeder::class);
    }
}
