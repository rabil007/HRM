import type { ReactElement } from 'react';
import { ActionImpactPreview } from '@/components/action-impact-preview';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';

const CHANNEL_LABELS: Record<string, string> = {
    in_app: 'App',
    email: 'Email',
    whatsapp: 'WhatsApp',
};

export function SendAnnouncementConfirmDialog({
    open,
    onOpenChange,
    recipientCount,
    channels,
    onConfirm,
    processing = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    recipientCount: number;
    channels: string[];
    onConfirm: () => void;
    processing?: boolean;
}): ReactElement {
    const channelLabels = channels.map(
        (channel) => CHANNEL_LABELS[channel] ?? channel,
    );

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card sm:max-w-lg">
                <AlertDialogHeader>
                    <AlertDialogTitle>Send Announcement</AlertDialogTitle>
                    <AlertDialogDescription>
                        Review the delivery impact before sending. This action
                        cannot simply be undone.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <ActionImpactPreview
                    severity="high"
                    title="What will happen"
                    impacts={[
                        ...(channelLabels.length > 0
                            ? [`Deliver through: ${channelLabels.join(', ')}.`]
                            : []),
                        `Send this announcement to ${recipientCount} employee${recipientCount === 1 ? '' : 's'}.`,
                        'Recipients receive the announcement through the selected channels according to existing delivery rules.',
                    ]}
                />

                <AlertDialogFooter>
                    <AlertDialogCancel disabled={processing}>
                        Go Back
                    </AlertDialogCancel>
                    <Button onClick={onConfirm} disabled={processing}>
                        Send Announcement
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
