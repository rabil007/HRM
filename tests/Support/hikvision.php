<?php

use App\Models\HikvisionSetting;

function configuredHikvisionSettings(): void
{
    HikvisionSetting::current()->storeFromValidated([
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'test-api-key',
        'api_secret' => 'test-api-secret',
        'enabled' => true,
    ]);
}
