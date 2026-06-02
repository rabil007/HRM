<?php

namespace App\Http\Controllers\Settings;

use App\Support\Settings\SettingsHubAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsHubController
{
    public function __invoke(Request $request, SettingsHubAccess $settingsHubAccess): Response
    {
        abort_unless($settingsHubAccess->allowed($request->user()), 403);

        return Inertia::render('settings/index');
    }
}
