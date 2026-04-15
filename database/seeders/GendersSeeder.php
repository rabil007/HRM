<?php

namespace Database\Seeders;

use App\Models\Gender;
use Illuminate\Database\Seeder;

class GendersSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Male',
            'Female',
            'Other',
        ] as $name) {
            Gender::query()->firstOrCreate([
                'name' => $name,
            ], [
                'is_active' => true,
            ]);
        }
    }
}
