<?php

namespace Database\Seeders;

use App\Models\ApprovalLocation;
use App\Models\SssaOption;
use Illuminate\Database\Seeder;

class ApprovalLocationsAndSssaOptionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'LZ Field',
            'USSC Field',
            'Zirku Island',
            'Das Island',
            'Umm Lulu',
            'Hail & Gasha',
            'Delma Island',
        ] as $name) {
            ApprovalLocation::query()->firstOrCreate(
                ['name' => $name],
                ['is_active' => true],
            );
        }

        foreach ([
            'Supply',
            'Well Stimulation / Well Test',
            'DP2',
            'Diving',
            'Other (Project Vessel)',
            'Port Engineer',
            'SSRV',
            'AHT',
            'Self-propelled jackup barge',
            'Crew Boat',
            'Port Captain',
        ] as $name) {
            SssaOption::query()->firstOrCreate(
                ['name' => $name],
                ['is_active' => true],
            );
        }
    }
}
