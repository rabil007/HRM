<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class BanksSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('database/seeders/data/banks.csv');

        if (! File::exists($path)) {
            return;
        }

        $lines = preg_split("/\r\n|\n|\r/", (string) File::get($path));
        $lines = array_values(array_filter($lines, fn ($l) => trim((string) $l) !== ''));

        if (count($lines) < 2) {
            return;
        }

        $headers = str_getcsv(array_shift($lines));
        $idx = array_flip($headers);

        $countriesByName = Country::query()
            ->get(['id', 'name'])
            ->keyBy(fn ($c) => mb_strtolower((string) $c->name));

        foreach ($lines as $line) {
            $row = str_getcsv($line);

            $name = trim((string) ($row[$idx['Name'] ?? -1] ?? ''));
            if ($name === '') {
                continue;
            }

            $routing = trim((string) ($row[$idx['UAE Routing Code Agent ID'] ?? -1] ?? '')) ?: null;
            $countryName = trim((string) ($row[$idx['Country'] ?? -1] ?? '')) ?: null;

            $countryId = null;
            if ($countryName) {
                $countryId = $countriesByName->get(mb_strtolower($countryName))?->id;
            }

            Bank::query()->updateOrCreate(
                [
                    'name' => $name,
                    'uae_routing_code_agent_id' => $routing,
                ],
                [
                    'country_id' => $countryId,
                    'is_active' => true,
                ]
            );
        }
    }
}
