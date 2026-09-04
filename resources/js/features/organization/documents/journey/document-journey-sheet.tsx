import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    Clock,
    ExternalLink,
    FileCheck,
    FileText,
    Mail,
    RefreshCw,
    RotateCcw,
    UserCheck,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import documentRoutes from '@/routes/organization/documents';
import type {
    DocumentJourneyData,
    JourneyEvent,
    OperationalTone,
} from './types';

export type DocumentJourneySheetProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    identifiers: {
        document_instance_id?: number | null;
        employee_document_id?: number | null;
        employee_id?: number | null;
        version_id?: number | null;
        document_type_key?: string | null;
        generation_run_id?: number | null;
    } | null;
};

export function badgeToneClasses(tone: OperationalTone): string {
    switch (tone) {
        case 'success':
            return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/20';
        case 'warning':
            return 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/20';
        case 'danger':
            return 'bg-rose-500/10 text-rose-700 dark:text-rose-300 border-rose-500/20';
        case 'info':
            return 'bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-500/20';
        case 'neutral':
        default:
            return 'bg-muted text-muted-foreground border-border';
    }
}

function eventIcon(type: string, status: string | null) {
    if (
        status === 'failed' ||
        type === 'failed' ||
        type === 'action_email_failed'
    ) {
        return <XCircle className="size-4 text-rose-600 dark:text-rose-400" />;
    }

    if (type === 'blocked') {
        return (
            <AlertTriangle className="size-4 text-amber-600 dark:text-amber-400" />
        );
    }

    if (type === 'completed' || type === 'signed' || status === 'approved') {
        return (
            <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" />
        );
    }

    if (type === 'reviewed' && status === 'rejected') {
        return <XCircle className="size-4 text-rose-600 dark:text-rose-400" />;
    }

    if (type === 'action_email_sent' || type === 'copy_email_sent') {
        return <Mail className="size-4 text-sky-600 dark:text-sky-400" />;
    }

    if (type === 'signing_requested') {
        return <FileCheck className="size-4 text-primary" />;
    }

    if (type === 'review_requested') {
        return (
            <UserCheck className="size-4 text-amber-600 dark:text-amber-400" />
        );
    }

    if (type === 'generated') {
        return <FileText className="size-4 text-primary" />;
    }

    return <Clock className="size-4 text-muted-foreground" />;
}

export function DocumentJourneySheet({
    open,
    onOpenChange,
    identifiers,
}: DocumentJourneySheetProps) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [journeyData, setJourneyData] = useState<DocumentJourneyData | null>(
        null,
    );
    const [resendingEmail, setResendingEmail] = useState(false);

    const fetchJourney = useCallback(async () => {
        if (!identifiers) {
            return;
        }

        setLoading(true);
        setError(null);

        try {
            const url = documentRoutes.journey.url({
                query: {
                    document_instance_id:
                        identifiers.document_instance_id ?? undefined,
                    employee_document_id:
                        identifiers.employee_document_id ?? undefined,
                    employee_id: identifiers.employee_id ?? undefined,
                    version_id: identifiers.version_id ?? undefined,
                    document_type_key:
                        identifiers.document_type_key ?? undefined,
                    generation_run_id:
                        identifiers.generation_run_id ?? undefined,
                },
            });

            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                if (response.status === 404) {
                    throw new Error(
                        'Document journey not found for this employee.',
                    );
                }

                throw new Error(
                    'Failed to load document journey. Please try again.',
                );
            }

            const data = (await response.json()) as DocumentJourneyData;
            setJourneyData(data);
        } catch (err: unknown) {
            const msg =
                err instanceof Error
                    ? err.message
                    : 'An unexpected error occurred.';
            setError(msg);
        } finally {
            setLoading(false);
        }
    }, [identifiers]);

    useEffect(() => {
        if (open && identifiers) {
            void fetchJourney();
        } else if (!open) {
            setJourneyData(null);
            setError(null);
        }
    }, [open, identifiers, fetchJourney]);

    const handleResendActionEmail = async (recipientRequestId: number) => {
        setResendingEmail(true);

        try {
            const url = documentRoutes.recipientRequests.email.url({
                recipientRequest: recipientRequestId,
            });

            router.post(
                url,
                {},
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.success('Action email queued for delivery.');
                        void fetchJourney();
                    },
                    onError: () => {
                        toast.error(
                            'Failed to resend action email. Please check email configuration.',
                        );
                    },
                    onFinish: () => {
                        setResendingEmail(false);
                    },
                },
            );
        } catch {
            setResendingEmail(false);
            toast.error('Could not initiate email resend.');
        }
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col gap-0 p-0 sm:max-w-xl"
            >
                {/* Header */}
                <SheetHeader className="border-b border-border p-6 text-left">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <SheetTitle className="text-lg leading-tight font-semibold text-foreground">
                                Document Journey
                            </SheetTitle>
                            <SheetDescription className="mt-1 text-sm text-muted-foreground">
                                {journeyData ? (
                                    <span>
                                        {journeyData.employee.name}
                                        {journeyData.employee.employee_no &&
                                            ` · ${journeyData.employee.employee_no}`}
                                    </span>
                                ) : (
                                    'Review complete document history, approval stages, and signatures.'
                                )}
                            </SheetDescription>
                        </div>
                        {journeyData && (
                            <Badge
                                variant="outline"
                                className={cn(
                                    'shrink-0 px-2.5 py-0.5 text-xs font-semibold',
                                    badgeToneClasses(journeyData.process.tone),
                                )}
                            >
                                {journeyData.process.label}
                            </Badge>
                        )}
                    </div>

                    {/* Department & Position */}
                    {journeyData && (
                        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            {journeyData.employee.department && (
                                <span>
                                    Dept:{' '}
                                    <strong className="font-medium text-foreground">
                                        {journeyData.employee.department}
                                    </strong>
                                </span>
                            )}
                            {journeyData.employee.position && (
                                <span>
                                    Position:{' '}
                                    <strong className="font-medium text-foreground">
                                        {journeyData.employee.position}
                                    </strong>
                                </span>
                            )}
                        </div>
                    )}
                </SheetHeader>

                {/* Content Area */}
                <div className="flex-1 space-y-6 overflow-y-auto p-6">
                    {loading && (
                        <div className="space-y-4">
                            <Skeleton className="h-16 w-full rounded-xl" />
                            <Skeleton className="h-28 w-full rounded-xl" />
                            <div className="space-y-3 pt-2">
                                <Skeleton className="h-12 w-full rounded-lg" />
                                <Skeleton className="h-12 w-full rounded-lg" />
                                <Skeleton className="h-12 w-full rounded-lg" />
                            </div>
                        </div>
                    )}

                    {error && (
                        <div className="flex flex-col items-center justify-center rounded-xl border border-destructive/30 bg-destructive/5 p-6 text-center">
                            <AlertTriangle className="mb-2 size-8 text-destructive" />
                            <p className="text-sm font-medium text-destructive">
                                {error}
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => void fetchJourney()}
                                className="mt-4 gap-1.5"
                            >
                                <RefreshCw className="size-3.5" />
                                Retry
                            </Button>
                        </div>
                    )}

                    {!loading && !error && journeyData && (
                        <>
                            {/* Document Card */}
                            <div className="rounded-xl border border-border bg-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">
                                        <h4 className="truncate text-sm font-semibold text-foreground">
                                            {journeyData.document.title}
                                        </h4>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {journeyData.document
                                                .document_type ??
                                                'Standard Document'}
                                            {journeyData.document
                                                .version_number &&
                                                ` · Version ${journeyData.document.version_number}`}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-1.5">
                                        {journeyData.document.view_url && (
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="sm"
                                                className="h-8 gap-1 text-xs"
                                            >
                                                <a
                                                    href={
                                                        journeyData.document
                                                            .view_url
                                                    }
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    <ExternalLink className="size-3.5" />
                                                    Preview
                                                </a>
                                            </Button>
                                        )}
                                        {journeyData.document.details_url && (
                                            <Button
                                                asChild
                                                variant="ghost"
                                                size="sm"
                                                className="h-8 gap-1 text-xs"
                                            >
                                                <a
                                                    href={
                                                        journeyData.document
                                                            .details_url
                                                    }
                                                >
                                                    Files
                                                    <ArrowRight className="size-3.5" />
                                                </a>
                                            </Button>
                                        )}
                                    </div>
                                </div>

                                {journeyData.process.waiting_for && (
                                    <div className="mt-3 flex items-center gap-2 rounded-lg bg-muted/60 px-3 py-2 text-xs">
                                        <span className="text-muted-foreground">
                                            Currently waiting for:
                                        </span>
                                        <span className="font-semibold text-foreground">
                                            {journeyData.process.waiting_for}
                                        </span>
                                    </div>
                                )}
                            </div>

                            {/* Action Email Failure Banner */}
                            {journeyData.action_email_banner?.show && (
                                <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-left">
                                    <div className="flex items-start gap-3">
                                        <AlertTriangle className="mt-0.5 size-5 shrink-0 text-rose-600 dark:text-rose-400" />
                                        <div className="min-w-0 flex-1">
                                            <h5 className="text-sm font-semibold text-rose-900 dark:text-rose-200">
                                                Action Email Delivery Failed
                                            </h5>
                                            <p className="mt-0.5 text-xs text-rose-700 dark:text-rose-300">
                                                {
                                                    journeyData
                                                        .action_email_banner
                                                        .message
                                                }
                                            </p>
                                            {journeyData.action_email_banner
                                                .can_resend &&
                                                journeyData.action_email_banner
                                                    .recipient_request_id && (
                                                    <Button
                                                        variant="destructive"
                                                        size="sm"
                                                        disabled={
                                                            resendingEmail
                                                        }
                                                        onClick={() =>
                                                            handleResendActionEmail(
                                                                journeyData
                                                                    .action_email_banner!
                                                                    .recipient_request_id!,
                                                            )
                                                        }
                                                        className="mt-3 h-8 gap-1.5 text-xs"
                                                    >
                                                        <RotateCcw className="size-3.5" />
                                                        {resendingEmail
                                                            ? 'Resending email...'
                                                            : 'Resend action email'}
                                                    </Button>
                                                )}
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Timeline */}
                            <div>
                                <h4 className="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Timeline & Progress
                                </h4>

                                {journeyData.events.length === 0 ? (
                                    <div className="rounded-lg border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                                        No timeline events recorded yet.
                                    </div>
                                ) : (
                                    <div className="relative space-y-6 pl-6 before:absolute before:top-2 before:bottom-2 before:left-2.5 before:w-0.5 before:bg-border">
                                        {journeyData.events.map(
                                            (event: JourneyEvent) => (
                                                <div
                                                    key={event.id}
                                                    className="relative text-left"
                                                >
                                                    <div className="absolute top-0.5 -left-6 flex size-5 items-center justify-center rounded-full bg-background ring-4 ring-background">
                                                        {eventIcon(
                                                            event.type,
                                                            event.status,
                                                        )}
                                                    </div>

                                                    <div className="flex items-baseline justify-between gap-2">
                                                        <h5 className="text-xs font-semibold text-foreground">
                                                            {event.title}
                                                        </h5>
                                                        {event.relative && (
                                                            <span className="shrink-0 text-[11px] text-muted-foreground">
                                                                {event.relative}
                                                            </span>
                                                        )}
                                                    </div>

                                                    {event.description && (
                                                        <p className="mt-1 rounded-lg border border-border/50 bg-muted/40 p-2 text-xs whitespace-pre-line text-muted-foreground">
                                                            {event.description}
                                                        </p>
                                                    )}

                                                    {event.actor &&
                                                        event.actor !==
                                                            'System' && (
                                                            <p className="mt-0.5 text-[11px] text-muted-foreground">
                                                                By{' '}
                                                                <span className="font-medium text-foreground">
                                                                    {
                                                                        event.actor
                                                                    }
                                                                </span>
                                                            </p>
                                                        )}
                                                </div>
                                            ),
                                        )}
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </div>

                {/* Footer Actions */}
                {journeyData && (
                    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border bg-muted/20 p-4">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => onOpenChange(false)}
                        >
                            Close
                        </Button>

                        <div className="flex items-center gap-2">
                            {journeyData.process.authorized_action_url && (
                                <Button asChild size="sm" className="gap-1.5">
                                    <a
                                        href={
                                            journeyData.process
                                                .authorized_action_url
                                        }
                                    >
                                        Take Action
                                        <ArrowRight className="size-3.5" />
                                    </a>
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}
