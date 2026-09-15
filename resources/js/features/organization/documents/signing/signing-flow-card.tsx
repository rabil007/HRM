import { router } from '@inertiajs/react';
import ResendDocumentRecipientRequestEmailController from '@/actions/App/Http/Controllers/Organization/Documents/ResendDocumentRecipientRequestEmailController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type {
    SigningFlowStepSummary,
    SigningFlowSummary,
} from '@/features/organization/documents/signing/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cancel, retry } from '@/routes/organization/documents/signing-flows';

type Props = {
    flow: SigningFlowSummary;
    canCancel: boolean;
    canRetry: boolean;
    canResendEmail?: boolean;
};

function stepTitle(step: SigningFlowStepSummary): string {
    return (
        step.step_label ??
        step.recipient_role_label ??
        step.recipient_role ??
        'Step'
    );
}

export function SigningFlowCard({
    flow,
    canCancel,
    canRetry,
    canResendEmail = false,
}: Props) {
    return (
        <Card className="border-border/80 dark:border-white/10">
            <CardHeader className="pb-3">
                <CardTitle className="text-base">Signing flow</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 pt-0 text-sm">
                <div className="grid gap-2 sm:grid-cols-2">
                    <div>
                        <p className="text-muted-foreground">Preset</p>
                        <p className="font-medium">{flow.preset_name}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Status</p>
                        <p className="font-medium">{flow.status_label}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Started by</p>
                        <p className="font-medium">
                            {flow.started_by?.name ?? '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Current step</p>
                        <p className="font-medium">
                            {flow.current_step_sequence ?? '—'}
                        </p>
                    </div>
                </div>

                <ol className="space-y-2">
                    {flow.steps.map((step) => (
                        <li
                            key={step.sequence}
                            className="rounded-md border border-border/60 px-3 py-2"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <span className="font-medium">
                                    {step.sequence}. {stepTitle(step)} —{' '}
                                    <span className="font-normal text-muted-foreground capitalize">
                                        {step.status}
                                    </span>
                                </span>
                            </div>
                            {step.recipient_name &&
                            step.recipient_name !== stepTitle(step) ? (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {step.recipient_name}
                                </p>
                            ) : null}
                            {step.email_delivery ? (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Email: {step.email_delivery.status_label}
                                    {step.email_delivery.last_sent_at
                                        ? ` · Last sent ${formatDisplayDate(step.email_delivery.last_sent_at)}`
                                        : ''}
                                </p>
                            ) : null}
                            <div className="mt-1 flex flex-wrap items-center gap-3">
                                {step.respond_url ? (
                                    <a
                                        href={step.respond_url}
                                        className="inline-block text-xs text-primary underline"
                                    >
                                        Open respond page
                                    </a>
                                ) : null}
                                {canResendEmail &&
                                step.request_id &&
                                step.email_delivery?.can_resend ? (
                                    <button
                                        type="button"
                                        className="text-xs text-primary underline"
                                        onClick={() =>
                                            router.post(
                                                ResendDocumentRecipientRequestEmailController.url(
                                                    {
                                                        recipientRequest:
                                                            step.request_id!,
                                                    },
                                                ),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {step.email_delivery.status === 'failed'
                                            ? 'Retry email'
                                            : step.email_delivery.status ===
                                                'sent'
                                              ? 'Resend email'
                                              : 'Send email'}
                                    </button>
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ol>

                {flow.blocked_reason ? (
                    <p className="rounded-md bg-destructive/10 px-3 py-2 text-destructive">
                        {flow.blocked_reason}
                    </p>
                ) : null}

                <div className="flex flex-wrap gap-2">
                    {canRetry && flow.can_retry ? (
                        <Button
                            type="button"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    retry.url(flow.id),
                                    {},
                                    {
                                        preserveScroll: true,
                                    },
                                )
                            }
                        >
                            Retry
                        </Button>
                    ) : null}
                    {canCancel && flow.can_cancel ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    cancel.url(flow.id),
                                    {},
                                    {
                                        preserveScroll: true,
                                    },
                                )
                            }
                        >
                            Cancel flow
                        </Button>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}
