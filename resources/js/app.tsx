import { createInertiaApp, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
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
import { ensureAppServiceWorker } from '@/lib/register-app-service-worker';

/**
 * Register the root-scoped service worker and prompt when an update is waiting.
 */
function PwaUpdatePrompt() {
    useEffect(() => {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        let cancelled = false;

        void (async () => {
            try {
                const registration = await ensureAppServiceWorker();

                if (cancelled || !registration) {
                    return;
                }

                registration.addEventListener('updatefound', () => {
                    const worker = registration.installing;

                    if (!worker) {
                        return;
                    }

                    worker.addEventListener('statechange', () => {
                        if (
                            worker.state === 'installed' &&
                            navigator.serviceWorker.controller
                        ) {
                            toast('A new version is available', {
                                description:
                                    'Reload to get the latest updates.',
                                duration: Infinity,
                                action: {
                                    label: 'Reload',
                                    onClick: () => {
                                        worker.postMessage({
                                            type: 'SKIP_WAITING',
                                        });
                                        window.location.reload();
                                    },
                                },
                            });
                        }
                    });
                });
            } catch {
                // Service worker registration is optional for the app shell.
            }
        })();

        return () => {
            cancelled = true;
        };
    }, []);

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
            case name.startsWith('public/'):
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
