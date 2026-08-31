import { Link, router } from '@inertiajs/react';
import { Mail, MailWarning, PenLine } from 'lucide-react';
import ResendDocumentRecipientRequestEmailController from '@/actions/App/Http/Controllers/Organization/Documents/ResendDocumentRecipientRequestEmailController';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { RecipientRequestListItem } from '@/features/organization/documents/workflow/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import documentRoutes from '@/routes/organization/documents';

/** Deterministic avatar colour from name. */
function avatarColour(name: string | null): string {
    const colours = [
        'bg-blue-500',
        'bg-violet-500',
        'bg-emerald-500',
        'bg-amber-500',
        'bg-rose-500',
        'bg-cyan-500',
        'bg-fuchsia-500',
        'bg-teal-500',
    ];
    const seed = (name ?? '').charCodeAt(0) || 0;

    return colours[seed % colours.length];
}

const ACTION_COLOURS: Record<string, string> = {
    sign: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
    acknowledge: 'bg-blue-500/10 text-blue-700 dark:text-blue-300',
    countersign: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
};

const STATUS_COLOURS: Record<string, string> = {
    awaiting_action: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    completed: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    expired: 'bg-red-500/10 text-red-700 dark:text-red-300',
    cancelled: 'bg-muted text-muted-foreground',
    superseded: 'bg-muted text-muted-foreground',
};

const EMAIL_STATUS_META: Record<
    string,
    { icon: typeof Mail; colour: string; label: string }
> = {
    queued: {
        icon: Mail,
        colour: 'text-muted-foreground',
        label: 'Email queued',
    },
    sent: {
        icon: Mail,
        colour: 'text-emerald-600 dark:text-emerald-400',
        label: 'Email sent',
    },
    failed: {
        icon: MailWarning,
        colour: 'text-red-500',
        label: 'Email failed',
    },
    suppressed: {
        icon: Mail,
        colour: 'text-muted-foreground',
        label: 'Email suppressed',
    },
};

/** Returns true when the expiry date is within 3 calendar days from now. */
function isExpiringSoon(expiresAt: string | null): boolean {
    if (!expiresAt) {
        return false;
    }

    const diff = new Date(expiresAt).getTime() - Date.now();

    return diff > 0 && diff < 3 * 24 * 60 * 60 * 1000;
}

type Props = {
    requests: RecipientRequestListItem[];
    canRespond?: boolean;
    canCreate?: boolean;
};

export function RecipientRequestsTable({
    requests,
    canRespond = false,
    canCreate = false,
}: Props) {
    if (requests.length === 0) {
        return (
            <EmptyState
                icon={
                    <PenLine className="mx-auto mb-2 h-8 w-8 text-muted-foreground/50" />
                }
                title="No signing requests yet"
                description="Signing and acknowledgement requests will appear here."
            />
        );
    }

    return (
        <div className="overflow-hidden rounded-xl border border-border/80 bg-card shadow-xs">
            <Table>
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        <TableHead className="w-[22%]">Employee</TableHead>
                        <TableHead className="w-[28%]">Document</TableHead>
                        <TableHead className="w-[18%]">Waiting for</TableHead>
                        <TableHead className="w-[14%]">Status</TableHead>
                        <TableHead className="w-[10%]">Expires</TableHead>
                        <TableHead className="w-[8%] text-right">
                            Action
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {requests.map((request) => {
                        const initial = (request.employee.name ?? '?')
                            .charAt(0)
                            .toUpperCase();
                        const colour = avatarColour(request.employee.name);
                        const actionKey = request.action?.toLowerCase() ?? '';
                        const emailMeta = request.email_delivery?.status
                            ? EMAIL_STATUS_META[request.email_delivery.status]
                            : null;
                        const expiringSoon = isExpiringSoon(request.expires_at);

                        return (
                            <TableRow
                                key={request.id}
                                className="transition-colors hover:bg-muted/40"
                            >
                                {/* Employee */}
                                <TableCell>
                                    <div className="flex items-center gap-3">
                                        <div
                                            className={cn(
                                                'flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white',
                                                colour,
                                            )}
                                        >
                                            {initial}
                                        </div>
                                        <div>
                                            <div className="font-medium text-foreground">
                                                {request.employee.name}
                                            </div>
                                            {request.employee.employee_no && (
                                                <div className="font-mono text-[11px] text-muted-foreground">
                                                    {
                                                        request.employee
                                                            .employee_no
                                                    }
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </TableCell>

                                {/* Document + signing step */}
                                <TableCell>
                                    <div className="font-medium text-foreground">
                                        {request.document.title ?? 'Document'}
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-1.5">
                                        {request.action && (
                                            <span
                                                className={cn(
                                                    'inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase',
                                                    ACTION_COLOURS[actionKey] ??
                                                        'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {request.action_label}
                                            </span>
                                        )}
                                        {request.signing_step_label && (
                                            <span className="text-[11px] text-muted-foreground">
                                                · {request.signing_step_label}
                                            </span>
                                        )}
                                    </div>
                                </TableCell>

                                {/* Waiting for + email chip */}
                                <TableCell>
                                    <div className="text-sm text-muted-foreground">
                                        {request.waiting_for || '—'}
                                    </div>
                                    {emailMeta && (
                                        <div
                                            className={cn(
                                                'mt-0.5 flex items-center gap-1 text-[11px]',
                                                emailMeta.colour,
                                            )}
                                            title={emailMeta.label}
                                        >
                                            <emailMeta.icon className="h-3 w-3" />
                                            <span>{emailMeta.label}</span>
                                        </div>
                                    )}
                                </TableCell>

                                {/* Status */}
                                <TableCell>
                                    <Badge
                                        variant="secondary"
                                        className={cn(
                                            'text-[10px] font-semibold tracking-wide uppercase',
                                            STATUS_COLOURS[request.status] ??
                                                '',
                                        )}
                                    >
                                        {request.human_status}
                                    </Badge>
                                </TableCell>

                                {/* Expires */}
                                <TableCell>
                                    {request.expires_at ? (
                                        <span
                                            className={cn(
                                                'text-sm',
                                                expiringSoon
                                                    ? 'font-medium text-amber-600 dark:text-amber-400'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {formatDisplayDate(
                                                request.expires_at,
                                            )}
                                            {expiringSoon && (
                                                <span className="ml-1 text-[10px]">
                                                    ⚠
                                                </span>
                                            )}
                                        </span>
                                    ) : (
                                        <span className="text-sm text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </TableCell>

                                {/* Actions */}
                                <TableCell className="text-right">
                                    <div className="flex items-center justify-end gap-1.5">
                                        {canRespond && request.respond_url && (
                                            <Button
                                                variant="default"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={request.respond_url}
                                                >
                                                    Sign
                                                </Link>
                                            </Button>
                                        )}
                                        {canCreate &&
                                            request.email_delivery
                                                ?.can_resend && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            ResendDocumentRecipientRequestEmailController.url(
                                                                {
                                                                    recipientRequest:
                                                                        request.id,
                                                                },
                                                            ),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {request.email_delivery
                                                        .status === 'failed'
                                                        ? 'Retry'
                                                        : request.email_delivery
                                                                .status ===
                                                            'sent'
                                                          ? 'Resend'
                                                          : 'Send'}
                                                </Button>
                                            )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={documentRoutes.recipientRequests.show.url(
                                                    {
                                                        recipientRequest:
                                                            request.id,
                                                    },
                                                )}
                                            >
                                                View
                                            </Link>
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
