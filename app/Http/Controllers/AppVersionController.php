<?php

namespace App\Http\Controllers;

use App\Support\AppRefresh\AuthorizationRevision;
use App\Support\AppRefresh\DeployedApplicationVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppVersionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentCompanyId = $request->attributes->get('current_company_id');
        $companyId = $currentCompanyId !== null ? (int) $currentCompanyId : null;

        $version = DeployedApplicationVersion::current();
        $clientVersion = $request->query('client_version');
        $updateAvailable = is_string($clientVersion)
            && $clientVersion !== ''
            && $clientVersion !== $version;

        return response()
            ->json([
                'version' => $version,
                'update_available' => $updateAvailable,
                'authorization_revision' => $user !== null
                    ? AuthorizationRevision::current($user, $companyId)
                    : null,
            ])
            ->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
