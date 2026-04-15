<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\PermissionRegistrar;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $currentCompanyId = $request->attributes->get('current_company_id');
        $user = $request->user();

        $companies = [];
        $permissions = [];
        $roleNames = [];

        if ($user) {
            $companies = $user->companies()->orderBy('name')->get(['companies.id', 'companies.name'])->all();

            if (empty($companies) && $user->company_id) {
                $fallback = Company::query()->whereKey($user->company_id)->get(['id', 'name'])->all();
                $companies = $fallback;
            }

            if ($currentCompanyId) {
                app(PermissionRegistrar::class)->setPermissionsTeamId((int) $currentCompanyId);
            }

            $permissions = $user->getAllPermissions()->pluck('name')->all();
            $roleNames = $user->getRoleNames()->all();
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
            ],
            'auth' => [
                'user' => $request->user(),
                'permissions' => $permissions,
                'roles' => $roleNames,
            ],
            'company_switcher_companies' => $companies,
            'current_company_id' => $currentCompanyId,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
