import { createInertiaApp, router } from '@inertiajs/react';
import { HttpExceptionToasts } from '@/components/http-exception-toasts';
import { PwaUpdatePrompt } from '@/components/pwa-update-prompt';
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
import { inertiaPageLayoutKind } from '@/lib/inertia-page-layout';

seedApplicationAppNameFromDom();

router.on('success', (event) => {
    syncApplicationAppNameFromInertiaPage(event.detail.page);
});

createInertiaApp({
    title: (title) => formatDocumentTitle(title),
    layout: (name) => {
        switch (inertiaPageLayoutKind(name)) {
            case 'none':
                return null;
            case 'auth':
                return AuthLayout;
            case 'settings':
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
