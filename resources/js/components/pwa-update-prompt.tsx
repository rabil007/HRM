import { useEffect } from 'react';
import { toast } from 'sonner';
import { ensureAppServiceWorker } from '@/lib/register-app-service-worker';

/**
 * Register the root-scoped service worker and prompt when an update is waiting.
 */
export function PwaUpdatePrompt() {
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
