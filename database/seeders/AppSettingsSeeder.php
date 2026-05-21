<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Support\Settings\SettingKey;
use Illuminate\Database\Seeder;

class AppSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SettingKey::defaults() as $key => $value) {
            AppSetting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => $value, 'type' => 'string'],
            );
        }
    }
}
