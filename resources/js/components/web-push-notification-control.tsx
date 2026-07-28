import { Button } from '@/components/ui/button';
import type { WebPushStatus } from '@/hooks/use-web-push-subscription';
import { useWebPushContext } from '@/hooks/use-web-push-subscription';

function statusLabel(status: WebPushStatus): string {
    switch (status) {
        case 'error':
            return 'Browser notifications could not be updated.';
        case 'unsupported':
            return 'Browser notifications are not available in this browser.';
        case 'requesting_permission':
            return 'Waiting for browser permission…';
        case 'subscribing':
            return 'Enabling browser notifications…';
        case 'denied':
            return 'Browser notifications are blocked. Enable them in your browser settings.';
        case 'enabled':
            return 'Browser notifications are on for this device.';
        default:
            return 'Get announcements as browser notifications on this device.';
    }
}

export function WebPushNotificationControl() {
    const {
        status,
        errorMessage,
        enable,
        disable,
        sendTest,
        testStatus,
        testMessage,
        serverConfigured,
    } = useWebPushContext();

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
            {testMessage ? (
                <p
                    className={
                        testStatus === 'error'
                            ? 'text-xs text-destructive'
                            : 'text-xs text-muted-foreground'
                    }
                >
                    {testMessage}
                </p>
            ) : null}
            {status === 'enabled' ? (
                <div className="space-y-2">
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        className="w-full"
                        disabled={testStatus === 'sending'}
                        onClick={() => {
                            void sendTest();
                        }}
                    >
                        {testStatus === 'sending'
                            ? 'Sending test…'
                            : 'Send test notification'}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-full"
                        disabled={testStatus === 'sending'}
                        onClick={() => {
                            void disable();
                        }}
                    >
                        Disable browser notifications
                    </Button>
                </div>
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
