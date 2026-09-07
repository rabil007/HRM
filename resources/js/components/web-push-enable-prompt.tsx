import { usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useCallback, useState, useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useWebPushContext } from '@/hooks/use-web-push-subscription';
import {
    dismissWebPushEnablePrompt,
    isWebPushEnablePromptDismissed,
    readWebPushPromptDismissedUntil,
    shouldShowWebPushEnablePrompt,
    subscribeWebPushPromptDismissal,
} from '@/lib/web-push-enable-prompt';

type SharedProps = {
    auth?: {
        user?: { id: number } | null;
    };
};

function browserSupportsWebPush(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

function currentNotificationPermission(): string {
    if (typeof Notification === 'undefined') {
        return 'denied';
    }

    return Notification.permission;
}

export function WebPushEnablePrompt() {
    const page = usePage<SharedProps>();
    const userId = page.props.auth?.user?.id ?? null;
    const { status, enable, serverConfigured } = useWebPushContext();
    const [enableRequested, setEnableRequested] = useState(false);
    const isDismissed = useSyncExternalStore(
        subscribeWebPushPromptDismissal,
        () =>
            userId === null
                ? false
                : isWebPushEnablePromptDismissed(
                      readWebPushPromptDismissedUntil(userId),
                      Date.now(),
                  ),
        () => false,
    );

    const isEnabling =
        enableRequested &&
        (status === 'requesting_permission' || status === 'subscribing');

    const eligible = shouldShowWebPushEnablePrompt({
        userId,
        serverConfigured,
        browserSupportsWebPush: browserSupportsWebPush(),
        status,
        notificationPermission: currentNotificationPermission(),
        isDismissed,
    });

    const open = eligible || isEnabling;

    const handleNotNow = useCallback(() => {
        if (userId === null || isEnabling) {
            return;
        }

        dismissWebPushEnablePrompt(userId);
    }, [isEnabling, userId]);

    const handleEnable = useCallback(() => {
        setEnableRequested(true);
        void enable();
    }, [enable]);

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (!nextOpen) {
                    handleNotNow();
                }
            }}
        >
            <DialogContent
                className="sm:max-w-md"
                onPointerDownOutside={(event) => {
                    if (isEnabling) {
                        event.preventDefault();
                    }
                }}
                onEscapeKeyDown={(event) => {
                    if (isEnabling) {
                        event.preventDefault();
                    }
                }}
            >
                <DialogHeader>
                    <div className="mb-1 flex size-10 items-center justify-center rounded-full border border-border bg-muted">
                        <Bell className="size-5 text-foreground" />
                    </div>
                    <DialogTitle>Stay updated</DialogTitle>
                    <DialogDescription>
                        Enable browser notifications so you don’t miss important
                        OMS-HRM alerts, including announcements, document
                        compliance reminders, and Crew Operations updates.
                    </DialogDescription>
                </DialogHeader>
                <p className="text-sm text-muted-foreground">
                    You can manage notifications anytime from the notification
                    bell.
                </p>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={isEnabling}
                        onClick={handleNotNow}
                    >
                        Not now
                    </Button>
                    <Button
                        type="button"
                        disabled={isEnabling}
                        onClick={handleEnable}
                    >
                        {isEnabling ? (
                            <>
                                <Spinner className="size-4" />
                                Enabling…
                            </>
                        ) : (
                            'Enable notifications'
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
