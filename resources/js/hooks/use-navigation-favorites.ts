import { router, usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import {
    destroy as destroyFavorite,
    store as storeFavorite,
} from '@/actions/App/Http/Controllers/NavigationFavoriteController';
import { useAuthPermissions } from '@/hooks/use-has-permission';
import { isSidebarUrlVisible, NO_PLATFORM_ACCESS } from '@/lib/nav-visibility';
import {
    destinationKeyFromPathname,
    findNavigationDestination,
    isFavoriteDestinationKey,
    pathnameFromPageUrl,
    resolveAccessibleFavoriteItems,
} from '@/lib/navigation-favorites';
import type { Auth } from '@/types/auth';

export function useNavigationFavorites() {
    const page = usePage();
    const permissions = useAuthPermissions();
    const platform =
        (page.props.auth as Auth | undefined)?.platform ?? NO_PLATFORM_ACCESS;
    const sharedKeys = page.props.favorite_destination_keys;
    const keys = useMemo(() => sharedKeys ?? [], [sharedKeys]);
    const pathname = pathnameFromPageUrl(page.url);
    const currentKey = destinationKeyFromPathname(pathname);
    const currentDestination = currentKey
        ? findNavigationDestination(currentKey)
        : undefined;

    const accessibleItems = useMemo(
        () =>
            resolveAccessibleFavoriteItems(keys, (href) =>
                isSidebarUrlVisible(href, permissions, platform),
            ),
        [keys, permissions, platform],
    );

    const currentIsVisible = currentDestination
        ? isSidebarUrlVisible(currentDestination.href, permissions, platform)
        : false;
    const currentIsFavorite = isFavoriteDestinationKey(keys, currentKey);
    const canToggleCurrent = currentKey !== null && currentIsVisible;

    const add = useCallback((key: string) => {
        router.post(
            storeFavorite.url(),
            { key },
            { preserveScroll: true, preserveState: true },
        );
    }, []);

    const remove = useCallback((key: string) => {
        router.delete(destroyFavorite.url(key), {
            preserveScroll: true,
            preserveState: true,
        });
    }, []);

    const toggleCurrent = useCallback(() => {
        if (currentKey === null || !canToggleCurrent) {
            return;
        }

        if (currentIsFavorite) {
            remove(currentKey);

            return;
        }

        add(currentKey);
    }, [add, canToggleCurrent, currentIsFavorite, currentKey, remove]);

    return {
        keys,
        accessibleItems,
        currentKey,
        currentIsFavorite,
        canToggleCurrent,
        add,
        remove,
        toggleCurrent,
    };
}
