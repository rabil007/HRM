let cachedAppName: string | null = null;

export function setApplicationAppName(name: string | null | undefined): void {
    const trimmed = name?.trim();

    if (trimmed) {
        cachedAppName = trimmed;
    }
}

export function resolveApplicationAppName(): string {
    if (cachedAppName) {
        return cachedAppName;
    }

    if (typeof document !== 'undefined') {
        const fromMeta = document
            .querySelector('meta[name="app-name"]')
            ?.getAttribute('content');

        if (fromMeta?.trim()) {
            return fromMeta.trim();
        }
    }

    return import.meta.env.VITE_APP_NAME || 'Laravel';
}

export function formatDocumentTitle(pageTitle?: string | null): string {
    const appName = resolveApplicationAppName();

    return pageTitle ? `${pageTitle} - ${appName}` : appName;
}

export function syncApplicationAppNameFromInertiaPage(page: {
    props?: { name?: string; settings?: { app_name?: string } };
}): void {
    const name = page.props?.settings?.app_name ?? page.props?.name;

    setApplicationAppName(name);
}

export function seedApplicationAppNameFromDom(): void {
    if (typeof document === 'undefined') {
        return;
    }

    const pageJson = document.getElementById('app')?.dataset?.page;

    if (!pageJson) {
        return;
    }

    try {
        syncApplicationAppNameFromInertiaPage(JSON.parse(pageJson));
    } catch {
        //
    }
}
