import {
    AlertTriangle,
    CheckCircle2,
    FlaskConical,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type {
    AnnouncementTestChannelResult,
    AnnouncementTestDestinations,
    AnnouncementTestSendResponse,
} from '@/features/organization/announcements/types';
import { cn } from '@/lib/utils';

type TestChannel = 'email' | 'whatsapp';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    destinations: AnnouncementTestDestinations | null | undefined;
    selectedChannels: string[];
    processing: boolean;
    result: AnnouncementTestSendResponse | null;
    error: string | null;
    onSubmit: (channels: TestChannel[]) => void;
};

function buildDefaultChannels(
    selectedChannels: string[],
    destinations: AnnouncementTestDestinations | null | undefined,
): TestChannel[] {
    const next: TestChannel[] = [];

    if (selectedChannels.includes('email') && destinations?.email.available) {
        next.push('email');
    }

    if (
        selectedChannels.includes('whatsapp') &&
        destinations?.whatsapp.available
    ) {
        next.push('whatsapp');
    }

    return next;
}

function ChannelResultRow({
    label,
    result,
    masked,
}: {
    label: string;
    result: AnnouncementTestChannelResult | null | undefined;
    masked: string | null;
}) {
    if (!result) {
        return null;
    }

    if (!result.attempted) {
        return (
            <div className="rounded-lg border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-sm">
                <p className="flex items-center gap-2 font-medium text-amber-700 dark:text-amber-400">
                    <AlertTriangle className="size-4" />
                    {label}
                </p>
                <p className="mt-1 text-muted-foreground">{result.message}</p>
            </div>
        );
    }

    if (result.success) {
        return (
            <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/5 px-3 py-2 text-sm">
                <p className="flex items-center gap-2 font-medium text-emerald-700 dark:text-emerald-400">
                    <CheckCircle2 className="size-4" />
                    {label}
                </p>
                <p className="mt-1 text-muted-foreground">
                    Sent to {masked ?? 'your account'}
                </p>
            </div>
        );
    }

    return (
        <div className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm">
            <p className="flex items-center gap-2 font-medium text-destructive">
                <XCircle className="size-4" />
                {label} failed
            </p>
            <p className="mt-1 text-muted-foreground">{result.message}</p>
        </div>
    );
}

function SendAnnouncementTestDialogBody({
    destinations,
    selectedChannels,
    processing,
    result,
    error,
    onSubmit,
    onClose,
}: Omit<Props, 'open' | 'onOpenChange'> & { onClose: () => void }) {
    const emailSelected = selectedChannels.includes('email');
    const whatsappSelected = selectedChannels.includes('whatsapp');
    const emailAvailable = Boolean(destinations?.email.available);
    const whatsappAvailable = Boolean(destinations?.whatsapp.available);

    const [channels, setChannels] = useState<TestChannel[]>(() =>
        buildDefaultChannels(selectedChannels, destinations),
    );

    const canSubmit = channels.length > 0 && !processing;

    const toggleChannel = (channel: TestChannel, checked: boolean) => {
        setChannels((current) =>
            checked
                ? [...current, channel]
                : current.filter((value) => value !== channel),
        );
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <FlaskConical className="size-4 text-primary" />
                    Send test announcement
                </DialogTitle>
                <DialogDescription>
                    Send this announcement only to your own account so you can
                    verify the final Email and WhatsApp formatting before
                    publishing.
                </DialogDescription>
            </DialogHeader>

            {result ? (
                <div className="space-y-3">
                    <p className="text-sm font-medium">{result.message}</p>
                    <ChannelResultRow
                        label="Email"
                        result={result.email}
                        masked={
                            result.destinations.email.masked ??
                            destinations?.email.masked ??
                            null
                        }
                    />
                    <ChannelResultRow
                        label="WhatsApp"
                        result={result.whatsapp}
                        masked={
                            result.destinations.whatsapp.masked ??
                            destinations?.whatsapp.masked ??
                            null
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        Your announcement has not been published.
                    </p>
                </div>
            ) : (
                <div className="space-y-4">
                    <div className="space-y-2">
                        <p className="text-sm font-medium">Channels</p>

                        {emailSelected ? (
                            <label
                                className={cn(
                                    'flex items-start gap-3 rounded-xl border p-3',
                                    emailAvailable
                                        ? 'border-border/70 bg-muted/20'
                                        : 'border-amber-500/30 bg-amber-500/5',
                                )}
                            >
                                <Checkbox
                                    checked={channels.includes('email')}
                                    disabled={!emailAvailable || processing}
                                    onCheckedChange={(checked) =>
                                        toggleChannel('email', checked === true)
                                    }
                                    className="mt-0.5"
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium">
                                        Email
                                    </span>
                                    {emailAvailable ? (
                                        <span className="mt-0.5 block text-xs text-muted-foreground">
                                            {destinations?.email.masked}
                                        </span>
                                    ) : (
                                        <span className="mt-0.5 flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
                                            <AlertTriangle className="size-3.5" />
                                            No test email is available for your
                                            account.
                                        </span>
                                    )}
                                </span>
                            </label>
                        ) : null}

                        {whatsappSelected ? (
                            <label
                                className={cn(
                                    'flex items-start gap-3 rounded-xl border p-3',
                                    whatsappAvailable
                                        ? 'border-border/70 bg-muted/20'
                                        : 'border-amber-500/30 bg-amber-500/5',
                                )}
                            >
                                <Checkbox
                                    checked={channels.includes('whatsapp')}
                                    disabled={!whatsappAvailable || processing}
                                    onCheckedChange={(checked) =>
                                        toggleChannel(
                                            'whatsapp',
                                            checked === true,
                                        )
                                    }
                                    className="mt-0.5"
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium">
                                        WhatsApp
                                    </span>
                                    {whatsappAvailable ? (
                                        <span className="mt-0.5 block text-xs text-muted-foreground">
                                            {destinations?.whatsapp.masked}
                                        </span>
                                    ) : (
                                        <span className="mt-0.5 flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
                                            <AlertTriangle className="size-3.5" />
                                            No valid employee phone is linked to
                                            your account.
                                        </span>
                                    )}
                                </span>
                            </label>
                        ) : null}
                    </div>

                    {!emailAvailable &&
                    !whatsappAvailable &&
                    (emailSelected || whatsappSelected) ? (
                        <p className="text-sm text-muted-foreground">
                            No selected external channel has a test destination
                            for your account.
                        </p>
                    ) : null}

                    <p className="text-xs text-muted-foreground">
                        This does not publish the announcement or notify
                        employees.
                    </p>

                    {error ? (
                        <p className="text-sm text-destructive">{error}</p>
                    ) : null}
                </div>
            )}

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    disabled={processing}
                    onClick={onClose}
                >
                    {result ? 'Close' : 'Cancel'}
                </Button>
                {!result ? (
                    <Button
                        type="button"
                        disabled={!canSubmit}
                        onClick={() => onSubmit(channels)}
                    >
                        {processing ? 'Sending…' : 'Send test'}
                    </Button>
                ) : null}
            </DialogFooter>
        </>
    );
}

export function SendAnnouncementTestDialog({
    open,
    onOpenChange,
    destinations,
    selectedChannels,
    processing,
    result,
    error,
    onSubmit,
}: Props) {
    const dialogKey = useMemo(
        () =>
            [
                open ? 'open' : 'closed',
                selectedChannels.join(','),
                destinations?.email.masked ?? '',
                destinations?.whatsapp.masked ?? '',
            ].join('|'),
        [open, selectedChannels, destinations],
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                {open ? (
                    <SendAnnouncementTestDialogBody
                        key={dialogKey}
                        destinations={destinations}
                        selectedChannels={selectedChannels}
                        processing={processing}
                        result={result}
                        error={error}
                        onSubmit={onSubmit}
                        onClose={() => onOpenChange(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
