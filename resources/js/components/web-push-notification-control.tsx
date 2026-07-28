import { Button } from '@/components/ui/button';
import { useWebPushSubscription } from '@/hooks/use-web-push-subscription';
import type { WebPushStatus } from '@/hooks/use-web-push-subscription';

function statusLabel(status: WebPushStatus): string {
    switch (status) {
        case 'enabled':
            return 'Browser notifications are on for this device.';
        case 'denied':
            return 'Browser notifications are blocked. Enable them in your browser settings.';
        case 'unsupported':
            return 'Browser notifications are not available in this browser.';
        case 'requesting_permission':
            return 'Waiting for browser permission…';
        case 'subscribing':
            return 'Enabling browser notifications…';
        case 'error':
            return 'Browser notifications could not be updated.';
        default:
            return 'Get announcements as browser notifications on this device.';
    }
}

export function WebPushNotificationControl() {
    const { status, errorMessage, enable, disable, serverConfigured } =
        useWebPushSubscription();

    if (!serverConfigured && status === 'unsupported') {
        return null;
    }

    return (
        <div className="space-y-2 px-2 py-2">
            <p className="text-xs text-muted-foreground">
                {statusLabel(status)}
            </p>
            {errorMessage ? (
                <p className="text-xs text-destructive">{errorMessage}</p>
            ) : null}
            {status === 'enabled' ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-full"
                    onClick={() => {
                        void disable();
                    }}
                >
                    Disable browser notifications
                </Button>
            ) : null}
            {status === 'not_enabled' || status === 'error' ? (
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    className="w-full"
                    onClick={() => {
                        void enable();
                    }}
                >
                    Enable browser notifications
                </Button>
            ) : null}
        </div>
    );
}
