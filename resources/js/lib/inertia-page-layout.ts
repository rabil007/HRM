export type InertiaPageLayoutKind = 'none' | 'auth' | 'settings' | 'app';

export function inertiaPageLayoutKind(name: string): InertiaPageLayoutKind {
    if (
        name === 'welcome' ||
        name.startsWith('errors/') ||
        name.startsWith('esign/') ||
        name.startsWith('shared/') ||
        name.startsWith('public/') ||
        name.startsWith('document-action/')
    ) {
        return 'none';
    }

    if (name.startsWith('auth/')) {
        return 'auth';
    }

    if (name.startsWith('settings/')) {
        return 'settings';
    }

    return 'app';
}
