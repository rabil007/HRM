import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock3,
    FileText,
    Mail,
    MessageCircle,
    Send,
    Smartphone,
    Users,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { AnnouncementMessagePreview } from '@/features/organization/announcements/announcement-message-preview';
import { cn } from '@/lib/utils';
import type {
    AnnouncementCan,
    AnnouncementShow,
} from '@/features/organization/announcements/types';

const statusVariant = (
    status: string,
): 'success' | 'info' | 'destructive' | 'secondary' => {
    if (status === 'published') {
return 'success';
}

    if (status === 'scheduled') {
return 'info';
}

    if (status === 'cancelled' || status === 'failed') {
return 'destructive';
}

    return 'secondary';
};

type DeliveryStat = {
    label: string;
    value: number;
    Icon: LucideIcon;
    colorClass?: string;
};

function DeliveryStatCard({
    label,
    value,
    Icon,
    colorClass = 'text-muted-foreground',
}: DeliveryStat) {
    return (
        <div className="flex flex-col gap-2 rounded-xl border border-border/60 bg-muted/20 p-3 transition-colors hover:bg-muted/40">
            <div className={cn('flex items-center justify-between', colorClass)}>
                <Icon className="size-4" />
                <span className="text-xl font-bold tracking-tight text-foreground">
                    {value}
                </span>
            </div>
            <div className="text-xs text-muted-foreground">{label}</div>
        </div>
    );
}

function RecipientStatusCell({ value }: { value: string | null }) {
    if (!value) {
        return (
            <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground/50">
                <span className="size-1.5 rounded-full bg-muted-foreground/30" />
                —
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1.5 text-xs text-success">
            <CheckCircle2 className="size-3.5" />
            {value}
        </span>
    );
}

export default function AnnouncementShowPage({
    announcement,
    can,
}: {
    announcement: AnnouncementShow;
    can: AnnouncementCan;
}) {
    return (
        <>
            <Head title={announcement.title} />
            <Main>
                <DetailsHeader
                    title={announcement.title}
                    description={
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant={statusVariant(announcement.status)}>
                                {announcement.status_label}
                            </Badge>
                            <Badge
                                variant={
                                    announcement.priority === 'high' ||
                                    announcement.priority === 'urgent'
                                        ? 'warning'
                                        : 'outline'
                                }
                            >
                                {announcement.priority_label} priority
                            </Badge>
                        </div>
                    }
                    backHref="/organization/announcements"
                    backLabel="Announcements"
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.update &&
                            (announcement.status === 'draft' ||
                                announcement.status === 'scheduled') ? (
                                <Button asChild variant="outline">
                                    <Link
                                        href={`/organization/announcements/${announcement.id}/edit`}
                                    >
                                        Edit
                                    </Link>
                                </Button>
                            ) : null}
                            {can.publish &&
                            (announcement.status === 'draft' ||
                                announcement.status === 'scheduled') ? (
                                <Button
                                    onClick={() =>
                                        router.post(
                                            `/organization/announcements/${announcement.id}/publish`,
                                        )
                                    }
                                >
                                    Publish
                                </Button>
                            ) : null}
                            {can.cancel &&
                            announcement.status === 'scheduled' ? (
                                <Button
                                    variant="secondary"
                                    onClick={() =>
                                        router.post(
                                            `/organization/announcements/${announcement.id}/cancel`,
                                        )
                                    }
                                >
                                    Cancel schedule
                                </Button>
                            ) : null}
                            {can.retry &&
                            announcement.delivery_summary.failed > 0 ? (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/organization/announcements/${announcement.id}/retry`,
                                        )
                                    }
                                >
                                    Retry failed
                                </Button>
                            ) : null}
                            {can.update && announcement.status === 'draft' ? (
                                <Button
                                    variant="destructive"
                                    onClick={() =>
                                        router.delete(
                                            `/organization/announcements/${announcement.id}`,
                                        )
                                    }
                                >
                                    Delete draft
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(300px,0.6fr)]">
                    <Card className="overflow-hidden glass-card">
                        <CardHeader className="border-b border-border/60 bg-muted/20">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="size-4 text-primary" /> Message preview
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-6">
                            <AnnouncementMessagePreview
                                previews={announcement.channel_previews}
                                attachments={announcement.attachments}
                                canDownloadAttachments={can.download_attachments}
                                announcementId={announcement.id}
                            />
                        </CardContent>
                    </Card>

                    {/* Right sidebar */}
                    <div className="space-y-6">
                        {/* Delivery summary */}
                        <Card className="glass-card">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Send className="size-4 text-primary" /> Delivery summary
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid grid-cols-2 gap-3">
                                {(
                                    [
                                        {
                                            label: 'Recipients',
                                            value: announcement.delivery_summary
                                                .total_recipients,
                                            Icon: Users,
                                        },
                                        {
                                            label: 'In-app',
                                            value: announcement.delivery_summary
                                                .in_app_sent,
                                            Icon: Smartphone,
                                        },
                                        {
                                            label: 'Email',
                                            value: announcement.delivery_summary
                                                .email_sent,
                                            Icon: Mail,
                                        },
                                        {
                                            label: 'WhatsApp',
                                            value: announcement.delivery_summary
                                                .whatsapp_sent,
                                            Icon: MessageCircle,
                                        },
                                        {
                                            label: 'Failed',
                                            value: announcement.delivery_summary
                                                .failed,
                                            Icon: XCircle,
                                            colorClass: announcement.delivery_summary.failed > 0
                                                ? 'text-destructive'
                                                : 'text-muted-foreground',
                                        },
                                        {
                                            label: 'Skipped',
                                            value: announcement.delivery_summary
                                                .skipped,
                                            Icon: Clock3,
                                        },
                                    ] satisfies DeliveryStat[]
                                ).map((stat) => (
                                    <DeliveryStatCard key={stat.label} {...stat} />
                                ))}
                            </CardContent>
                        </Card>

                        {/* Details */}
                        <Card className="glass-card">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Clock3 className="size-4 text-primary" /> Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="grid gap-0 divide-y divide-border/50 text-sm">
                                    {[
                                        {
                                            label: 'Category',
                                            value: announcement.category_label,
                                        },
                                        {
                                            label: 'Audience',
                                            value: announcement.audience_summary,
                                        },
                                        {
                                            label: 'Channels',
                                            value: announcement.channels
                                                .map((c) => c.replace('_', ' '))
                                                .join(', '),
                                        },
                                        {
                                            label: 'Created by',
                                            value: announcement.created_by ?? '—',
                                        },
                                        {
                                            label: 'Published / scheduled',
                                            value:
                                                announcement.published_at ??
                                                announcement.scheduled_at ??
                                                '—',
                                        },
                                        {
                                            label: 'Expiry',
                                            value: announcement.expires_at ?? 'No expiry',
                                        },
                                    ].map(({ label, value }) => (
                                        <div key={label} className="grid grid-cols-2 gap-2 py-3 first:pt-0 last:pb-0">
                                            <dt className="text-muted-foreground">{label}</dt>
                                            <dd className="font-medium capitalize">{value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* Recipient activity table */}
                {announcement.recipients.length > 0 ? (
                    <Card className="mt-6 overflow-hidden glass-card">
                        <CardHeader className="border-b border-border/60">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Users className="size-4 text-primary" /> Recipient activity
                                <Badge variant="secondary" className="ml-auto font-mono text-xs">
                                    {announcement.recipients.length}
                                </Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="overflow-x-auto p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Department</TableHead>
                                        <TableHead>
                                            <div className="flex items-center gap-1.5">
                                                <Smartphone className="size-3.5" /> In-app
                                            </div>
                                        </TableHead>
                                        <TableHead>
                                            <div className="flex items-center gap-1.5">
                                                <Mail className="size-3.5" /> Email
                                            </div>
                                        </TableHead>
                                        <TableHead>
                                            <div className="flex items-center gap-1.5">
                                                <MessageCircle className="size-3.5" /> WhatsApp
                                            </div>
                                        </TableHead>
                                        <TableHead>Read</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {announcement.recipients.map(
                                        (recipient) => (
                                            <TableRow key={recipient.id}>
                                                <TableCell className="font-medium">
                                                    {recipient.employee_name}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {recipient.department ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <RecipientStatusCell value={recipient.in_app} />
                                                </TableCell>
                                                <TableCell>
                                                    <RecipientStatusCell value={recipient.email} />
                                                </TableCell>
                                                <TableCell>
                                                    <RecipientStatusCell value={recipient.whatsapp} />
                                                </TableCell>
                                                <TableCell>
                                                    <RecipientStatusCell value={recipient.read_at} />
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                ) : null}
            </Main>
        </>
    );
}
