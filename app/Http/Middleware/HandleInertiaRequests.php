<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Inertia\Support\Header;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

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
     * Prevent browsers and CDNs from caching Inertia JSON responses and serving
     * them on full page loads (e.g. duplicated tabs, hard refresh).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);

        if ($request->header(Header::INERTIA)) {
            $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');

            return $response;
        }

        if ($request->user() && $response->isSuccessful() && $this->isHtmlResponse($response)) {
            $response->headers->set('Cache-Control', 'private, no-cache, no-store, max-age=0, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }

    private function isHtmlResponse(Response $response): bool
    {
        $contentType = (string) $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html');
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
        $currentCompanyId = $request->attributes->get('current_company_id') ?? $request->session()->get('current_company_id');
        $user = $request->user();

        $companies = [];
        $permissions = [];
        $roleNames = [];

        if ($user) {
            $companiesCacheKey = "inertia:shared:{$user->id}:companies";
            $companies = Cache::remember($companiesCacheKey, now()->addSeconds(60), function () use ($user) {
                $companies = $user->companies()->orderBy('name')->get(['companies.id', 'companies.name'])->all();

                if (empty($companies) && $user->company_id) {
                    return Company::query()->whereKey($user->company_id)->get(['id', 'name'])->all();
                }

                return $companies;
            });

            if (! $currentCompanyId) {
                $currentCompanyId = $user->company_id ?: ($companies[0]->id ?? null);
            }

            $companyKeyPart = $currentCompanyId ? (int) $currentCompanyId : 'none';
            $permissionsCacheKey = "inertia:shared:{$user->id}:company:{$companyKeyPart}:permissions";
            $rolesCacheKey = "inertia:shared:{$user->id}:company:{$companyKeyPart}:roles";

            $permissions = Cache::remember($permissionsCacheKey, now()->addSeconds(60), function () use ($currentCompanyId, $user) {
                if ($currentCompanyId) {
                    app(PermissionRegistrar::class)->setPermissionsTeamId((int) $currentCompanyId);
                }

                return $user->getAllPermissions()->pluck('name')->all();
            });

            $roleNames = Cache::remember($rolesCacheKey, now()->addSeconds(60), function () use ($currentCompanyId, $user) {
                if ($currentCompanyId) {
                    app(PermissionRegistrar::class)->setPermissionsTeamId((int) $currentCompanyId);
                }

                return $user->getRoleNames()->all();
            });
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
