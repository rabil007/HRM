import { createInertiaApp, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { useRegisterSW } from 'virtual:pwa-register/react';
import { HttpExceptionToasts } from '@/components/http-exception-toasts';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import {
    formatDocumentTitle,
    seedApplicationAppNameFromDom,
    syncApplicationAppNameFromInertiaPage,
} from '@/lib/application-app-name';

/**
 * Listens for a new service worker install and prompts the user
 * to reload so they get the latest version of the app.
 */
function PwaUpdatePrompt() {
    useRegisterSW({
        onNeedRefresh(updateSW) {
            toast('A new version is available', {
                description: 'Reload to get the latest updates.',
                duration: Infinity,
                action: {
                    label: 'Reload',
                    onClick: () => updateSW(true),
                },
            });
        },
    });

    return null;
}

seedApplicationAppNameFromDom();

router.on('success', (event) => {
    syncApplicationAppNameFromInertiaPage(event.detail.page);
});

createInertiaApp({
    title: (title) => formatDocumentTitle(title),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('errors/'):
                return null;
            case name.startsWith('esign/'):
                return null;
            case name.startsWith('shared/'):
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
                <PwaUpdatePrompt />
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
