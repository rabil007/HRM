<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Services\Settings\SettingService;
use App\Support\Users\UserAvatar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
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
            $cachedCompanies = Cache::get($companiesCacheKey);

            if ($this->isValidCompanySwitcherCache($cachedCompanies)) {
                $companies = $cachedCompanies;
            } else {
                if ($cachedCompanies !== null) {
                    Cache::forget($companiesCacheKey);
                }

                $companies = Cache::remember($companiesCacheKey, now()->addSeconds(60), function () use ($user): array {
                    $models = $user->companies()->orderBy('name')->get(['companies.id', 'companies.name', 'companies.logo']);

                    if ($models->isEmpty() && $user->company_id) {
                        $models = Company::query()->whereKey($user->company_id)->get(['id', 'name', 'logo']);
                    }

                    return $models
                        ->map(fn (Company $company): array => $this->formatCompanySwitcherEntry($company))
                        ->all();
                });
            }

            if (! $currentCompanyId) {
                $currentCompanyId = $user->company_id ?: ($companies[0]['id'] ?? null);
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

        $settingService = app(SettingService::class);
        $applicationSettings = $settingService->forInertia();

        return [
            ...parent::share($request),
            'name' => $applicationSettings['app_name'],
            'settings' => $applicationSettings,
            'flash' => [
                'success' => $request->session()->pull('success'),
                'error' => $request->session()->pull('error'),
                'info' => $request->session()->pull('info'),
            ],
            'auth' => [
                'user' => $this->formatAuthUser($request->user()),
                'permissions' => $permissions,
                'roles' => $roleNames,
            ],
            'company_switcher_companies' => $companies,
            'current_company_id' => $currentCompanyId,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @param  array<int, array{id: int, name: string, logo_url: string|null}>|null  $cached
     */
    /**
     * @return array<string, mixed>|null
     */
    private function formatAuthUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $data = $user->toArray();
        $data['avatar'] = UserAvatar::url($user->avatar);

        return $data;
    }

    private function isValidCompanySwitcherCache(?array $cached): bool
    {
        if (! is_array($cached)) {
            return false;
        }

        if ($cached === []) {
            return true;
        }

        $first = $cached[0] ?? null;

        return is_array($first)
            && array_key_exists('id', $first)
            && array_key_exists('name', $first)
            && array_key_exists('logo_url', $first);
    }

    /**
     * @return array{id: int, name: string, logo_url: string|null}
     */
    private function formatCompanySwitcherEntry(Company $company): array
    {
        $publicDisk = Storage::disk('public');
        $logoPath = $company->logo;

        return [
            'id' => $company->id,
            'name' => $company->name,
            'logo_url' => $logoPath && $publicDisk->exists($logoPath)
                ? $publicDisk->url($logoPath)
                : null,
        ];
    }

    public static function forgetCompanySwitcherCacheForCompany(Company $company): void
    {
        $company->users()->pluck('users.id')->each(function (int $userId): void {
            Cache::forget("inertia:shared:{$userId}:companies");
        });
    }
}
