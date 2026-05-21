<?php

namespace Database\Seeders;

use App\Models\Course;
use Illuminate\Database\Seeder;

class CoursesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'STCW Basic Safety',
            'Advanced Fire Fighting',
            'Proficiency in Survival Craft',
        ] as $name) {
            Course::query()->firstOrCreate([
                'name' => $name,
            ], [
                'is_active' => true,
            ]);
        }
    }
}
