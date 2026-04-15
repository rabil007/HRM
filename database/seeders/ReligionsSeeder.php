<?php

namespace Database\Seeders;

use App\Models\Religion;
use Illuminate\Database\Seeder;

class ReligionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Muslim',
            'Christian',
            'Hindu',
            'Catholic',
            'Other',
        ] as $name) {
            Religion::query()->firstOrCreate([
                'name' => $name,
            ], [
                'is_active' => true,
            ]);
        }
    }
}
