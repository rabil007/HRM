import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { setApplicationAppName } from '@/lib/application-app-name';
import { applyBrandTheme } from '@/lib/theme/apply-brand-theme';

export function useApplicationSettings() {
    const { settings, name } = usePage().props;

    return {
        settings,
        appName: settings?.app_name ?? name ?? 'Laravel',
        branding: settings?.branding,
    };
}

/** Keeps document title meta and favicon in sync after Inertia navigations. */
export function useApplicationBrandingSync(): void {
    const { settings, name } = usePage().props;
    const appName = settings?.app_name ?? name;

    setApplicationAppName(appName);

    useEffect(() => {
        if (appName) {
            const meta = document.querySelector('meta[name="app-name"]');

            if (meta) {
                meta.setAttribute('content', appName);
            }
        }

        const faviconUrl = settings?.branding?.favicon_url;

        if (faviconUrl) {
            let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');

            if (! link) {
                link = document.createElement('link');
                link.rel = 'icon';
                document.head.appendChild(link);
            }

            link.href = faviconUrl;
        }

        const preferences = settings?.preferences;
        const primary = preferences?.primary_color ?? '#6366f1';
        const accent = preferences?.accent_color ?? '#8b5cf6';

        applyBrandTheme(primary, accent);
    }, [appName, settings, name]);
}

export function useSidebarDefaultOpen(): boolean {
    const { settings, sidebarOpen, sidebarStateSet } = usePage().props;
    const compactDefault = settings?.preferences?.sidebar_compact_default;

    // If the user has explicitly set their sidebar preference via the config
    // drawer, always respect it — even if the app default is compact.
    if (sidebarStateSet) {
        return sidebarOpen ?? true;
    }

    // No user preference yet — fall back to the app-level default.
    if (compactDefault === true) {
        return false;
    }

    return true;
}
