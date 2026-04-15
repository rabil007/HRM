<?php

namespace Database\Seeders;

use App\Models\VisaType;
use Illuminate\Database\Seeder;

class VisaTypesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Residential Visa',
            'Mission Visa',
            'Visit Visa',
        ] as $name) {
            VisaType::query()->firstOrCreate([
                'name' => $name,
            ], [
                'is_active' => true,
            ]);
        }
    }
}
