<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\NavigationFavorite;
use App\Models\User;
use App\Services\Settings\SettingService;
use App\Support\Auth\PrivilegedTwoFactorPolicy;
use App\Support\Companies\ResolveCompanyAccess;
use App\Support\Documents\MyTasks\MyTasksCounter;
use App\Support\Platform\PlatformAuthorization;
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
        if (app()->environment('testing')) {
            return 'testing';
        }

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
        $user = $request->user();
        $companyAccess = app(ResolveCompanyAccess::class);

        // Prefer the attribute validated by SetCurrentCompany. If it is missing
        // (middleware order / early Inertia share edge cases), re-apply the same
        // accessibility rules — never restore an inaccessible users.company_id.
        $currentCompanyId = $request->attributes->get('current_company_id');
        if ($currentCompanyId !== null) {
            $currentCompanyId = (int) $currentCompanyId;
        }

        $companies = [];
        $permissions = [];
        $roleNames = [];
        $favoriteDestinationKeys = [];

        if ($user) {
            $accessibleCompanyIds = $companyAccess->accessibleCompanyIds($user);

            if ($currentCompanyId === null) {
                $sessionCompanyId = $request->session()->get('current_company_id');
                if ($sessionCompanyId !== null && in_array((int) $sessionCompanyId, $accessibleCompanyIds, true)) {
                    $currentCompanyId = (int) $sessionCompanyId;
                } else {
                    $currentCompanyId = $companyAccess->resolveFallbackCompanyId($user, $accessibleCompanyIds);
                }

                if ($currentCompanyId !== null) {
                    $request->attributes->set('current_company_id', $currentCompanyId);
                    $request->session()->put('current_company_id', $currentCompanyId);
                    app(PermissionRegistrar::class)->setPermissionsTeamId($currentCompanyId);
                }
            }

            if ($currentCompanyId !== null && ! in_array($currentCompanyId, $accessibleCompanyIds, true)) {
                $currentCompanyId = null;
                $request->attributes->remove('current_company_id');
                $request->session()->forget('current_company_id');
                app(PermissionRegistrar::class)->setPermissionsTeamId(null);
            }

            if ($currentCompanyId === null && $accessibleCompanyIds === []) {
                $request->session()->forget('current_company_id');
                $request->attributes->remove('current_company_id');
                app(PermissionRegistrar::class)->setPermissionsTeamId(null);
            }

            $companiesCacheKey = "inertia:shared:{$user->id}:companies";
            $cachedCompanies = Cache::get($companiesCacheKey);
            $accessibleIdSet = array_fill_keys($accessibleCompanyIds, true);

            if (
                $this->isValidCompanySwitcherCache($cachedCompanies)
                && $this->cachedCompaniesMatchAccessible($cachedCompanies, $accessibleIdSet)
            ) {
                $companies = $cachedCompanies;
            } else {
                Cache::forget($companiesCacheKey);

                $companies = Cache::remember($companiesCacheKey, now()->addSeconds(60), function () use ($companyAccess, $user): array {
                    return $companyAccess->accessibleCompanies($user)
                        ->map(fn (Company $company): array => $this->formatCompanySwitcherEntry($company))
                        ->all();
                });
            }

            if ($currentCompanyId === null) {
                $permissions = [];
                $roleNames = [];
            } else {
                $companyKeyPart = (int) $currentCompanyId;
                $permissionsCacheKey = "inertia:shared:{$user->id}:company:{$companyKeyPart}:permissions";
                $rolesCacheKey = "inertia:shared:{$user->id}:company:{$companyKeyPart}:roles";

                $permissions = Cache::remember($permissionsCacheKey, now()->addSeconds(60), function () use ($currentCompanyId, $user) {
                    app(PermissionRegistrar::class)->setPermissionsTeamId((int) $currentCompanyId);

                    return $user->getAllPermissions()->pluck('name')->all();
                });

                $roleNames = Cache::remember($rolesCacheKey, now()->addSeconds(60), function () use ($currentCompanyId, $user) {
                    app(PermissionRegistrar::class)->setPermissionsTeamId((int) $currentCompanyId);

                    return $user->getRoleNames()->all();
                });
            }

            $favoriteDestinationKeys = $user->navigationFavorites()
                ->orderBy('position')
                ->orderBy('id')
                ->limit(NavigationFavorite::MAX_PER_USER)
                ->pluck('destination_key')
                ->values()
                ->all();
        }

        $settingService = app(SettingService::class);
        $applicationSettings = $settingService->forInertia(
            $currentCompanyId !== null ? (int) $currentCompanyId : null,
        );

        return [
            ...parent::share($request),
            'name' => $applicationSettings['platform']['app_name'] ?? $applicationSettings['app_name'],
            'settings' => $applicationSettings,
            'flash' => [
                'success' => $request->session()->pull('success'),
                'error' => $request->session()->pull('error'),
                'info' => $request->session()->pull('info'),
                'recipient_request_created' => $request->session()->pull('recipient_request_created'),
                'recipient_request_link_regenerated' => $request->session()->pull('recipient_request_link_regenerated'),
            ],
            'auth' => [
                'user' => $this->formatAuthUser($request->user()),
                'permissions' => $permissions,
                'roles' => $roleNames,
                'platform' => PlatformAuthorization::sharedFlags($user),
                'two_factor' => PrivilegedTwoFactorPolicy::sharedFlags($user),
                'my_tasks_count' => ($user && $currentCompanyId !== null)
                    ? app(MyTasksCounter::class)->count($user, (int) $currentCompanyId)
                    : 0,
            ],
            'company_switcher_companies' => $companies,
            'current_company_id' => $currentCompanyId,
            'favorite_destination_keys' => $favoriteDestinationKeys,
            'web_push' => [
                'vapid_public_key' => $user ? (string) (config('webpush.vapid.public_key') ?? '') : '',
                'enabled' => $user !== null
                    && filled(config('webpush.vapid.public_key'))
                    && filled(config('webpush.vapid.private_key')),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'sidebarStateSet' => $request->hasCookie('sidebar_state'),
        ];
    }

    /**
     * @param  array<int, array{id: int, name: string, logo_url: string|null}>  $cached
     * @param  array<int, true>  $accessibleIdSet
     */
    private function cachedCompaniesMatchAccessible(array $cached, array $accessibleIdSet): bool
    {
        if (count($cached) !== count($accessibleIdSet)) {
            return false;
        }

        foreach ($cached as $entry) {
            if (! isset($accessibleIdSet[(int) $entry['id']])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{id: int, name: string, logo_url: string|null}>|null  $cached
     */
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
