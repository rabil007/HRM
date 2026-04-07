<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            ['code' => 'UAE', 'name' => 'United Arab Emirates', 'dial_code' => '+971'],
            ['code' => 'KSA', 'name' => 'Saudi Arabia', 'dial_code' => '+966'],
            ['code' => 'QAT', 'name' => 'Qatar', 'dial_code' => '+974'],
            ['code' => 'KWT', 'name' => 'Kuwait', 'dial_code' => '+965'],
            ['code' => 'BHR', 'name' => 'Bahrain', 'dial_code' => '+973'],
            ['code' => 'OMN', 'name' => 'Oman', 'dial_code' => '+968'],
            ['code' => 'JOR', 'name' => 'Jordan', 'dial_code' => '+962'],
            ['code' => 'EGY', 'name' => 'Egypt', 'dial_code' => '+20'],

            ['code' => 'IND', 'name' => 'India', 'dial_code' => '+91'],
            ['code' => 'PAK', 'name' => 'Pakistan', 'dial_code' => '+92'],
            ['code' => 'BGD', 'name' => 'Bangladesh', 'dial_code' => '+880'],
            ['code' => 'LKA', 'name' => 'Sri Lanka', 'dial_code' => '+94'],
            ['code' => 'PHL', 'name' => 'Philippines', 'dial_code' => '+63'],

            ['code' => 'GBR', 'name' => 'United Kingdom', 'dial_code' => '+44'],
            ['code' => 'USA', 'name' => 'United States', 'dial_code' => '+1'],
            ['code' => 'CAN', 'name' => 'Canada', 'dial_code' => '+1'],
            ['code' => 'AUS', 'name' => 'Australia', 'dial_code' => '+61'],

            ['code' => 'TUR', 'name' => 'Turkey', 'dial_code' => '+90'],
            ['code' => 'CHN', 'name' => 'China', 'dial_code' => '+86'],
            ['code' => 'JPN', 'name' => 'Japan', 'dial_code' => '+81'],
            ['code' => 'SGP', 'name' => 'Singapore', 'dial_code' => '+65'],
            ['code' => 'MYS', 'name' => 'Malaysia', 'dial_code' => '+60'],
        ];

        foreach ($countries as $country) {
            Country::query()->updateOrCreate(
                ['code' => $country['code']],
                [
                    'name' => $country['name'],
                    'dial_code' => $country['dial_code'],
                    'is_active' => true,
                ]
            );
        }
    }
}
