import { createInertiaApp } from '@inertiajs/react';
import { HttpExceptionToasts } from '@/components/http-exception-toasts';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

function resolveAppName(): string {
    if (typeof document !== 'undefined') {
        const fromMeta = document.querySelector('meta[name="app-name"]')?.getAttribute('content');

        if (fromMeta?.trim()) {
            return fromMeta.trim();
        }
    }

    return import.meta.env.VITE_APP_NAME || 'Laravel';
}

createInertiaApp({
    title: (title) => {
        const appName = resolveAppName();

        return title ? `${title} - ${appName}` : appName;
    },
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                <HttpExceptionToasts />
                <Toaster duration={5000} />
                {app}
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
        delay: 250,
    },
});

initializeTheme();
