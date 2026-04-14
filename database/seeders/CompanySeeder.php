<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $currency = Currency::query()->where('code', 'AED')->first()
            ?? Currency::query()->orderBy('id')->first();

        $country = Country::query()->where('code', 'UAE')->first()
            ?? Country::query()->orderBy('id')->first();

        if (! $currency || ! $country) {
            return;
        }

        $name = 'Herd OMS';

        Company::query()->updateOrCreate(
            ['slug' => Str::slug($name)],
            [
                'name' => $name,
                'working_days' => [1, 2, 3, 4, 5],
                'country_id' => $country->id,
                'currency_id' => $currency->id,
                'timezone' => 'Asia/Dubai',
                'payroll_cycle' => 'monthly',
                'status' => 'active',
            ]
        );
    }
}
