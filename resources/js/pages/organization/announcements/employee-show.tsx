import { Head, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarDays,
    CheckCircle2,
    Clock,
    Download,
    FileText,
    Megaphone,
    Tag,
    TriangleAlert,
} from 'lucide-react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

const priorityVariant = (priority: string): 'warning' | 'outline' => {
    if (priority === 'urgent' || priority === 'high') {
        return 'warning';
    }

    return 'outline';
};

export default function EmployeeAnnouncementShow({
    announcement,
    recipient_id,
    can_acknowledge,
}: {
    announcement: {
        title: string;
        body_html: string;
        priority: string;
        category: string;
        published_at: string | null;
        requires_acknowledgement: boolean;
        acknowledged_at: string | null;
        attachments: { id: number; original_name: string }[];
    };
    recipient_id: number;
    can_acknowledge: boolean;
}) {
    const isAcknowledged = !!announcement.acknowledged_at;
    const needsAck =
        announcement.requires_acknowledgement && !isAcknowledged && can_acknowledge;

    return (
        <>
            <Head title={announcement.title} />
            <Main>
                <PageHeader
                    title={announcement.title}
                    kicker="Employee communications"
                    description={`${announcement.category} · ${announcement.priority} priority`}
                    right={
                        <Button
                            variant="outline"
                            onClick={() => window.history.back()}
                        >
                            <ArrowLeft className="size-4" /> Back
                        </Button>
                    }
                />
                <div className="-mt-4 mb-6 flex flex-wrap items-center gap-2">
                    <Badge variant={priorityVariant(announcement.priority)}>
                        {announcement.priority} priority
                    </Badge>
                    <Badge variant="secondary">{announcement.category}</Badge>
                </div>

                {/* Acknowledgement required banner */}
                {needsAck ? (
                    <div className="mb-6 flex items-start gap-3 rounded-xl border border-warning/40 bg-warning/5 p-4 text-sm text-warning">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        <p>
                            This announcement requires your acknowledgement. Please read the
                            full message and confirm at the bottom of the page.
                        </p>
                    </div>
                ) : null}

                <div className="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
                    {/* Message body */}
                    <Card className="overflow-hidden glass-card">
                        <CardHeader className="border-b border-border/60 bg-muted/20">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Megaphone className="size-4 text-primary" /> Announcement
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6 pt-6">
                            <div
                                className="prose prose-sm dark:prose-invert max-w-none leading-7"
                                dangerouslySetInnerHTML={{
                                    __html: announcement.body_html,
                                }}
                            />

                            {/* Acknowledgement CTA inside content card */}
                            {needsAck ? (
                                <div className="rounded-xl border border-primary/30 bg-primary/5 p-5">
                                    <p className="mb-3 text-sm font-medium">
                                        By clicking below, you confirm that you have read and
                                        understood this announcement.
                                    </p>
                                    <Button
                                        onClick={() =>
                                            router.post(
                                                `/organization/announcements/inbox/${recipient_id}/acknowledge`,
                                            )
                                        }
                                    >
                                        <CheckCircle2 className="size-4" />
                                        Acknowledge receipt
                                    </Button>
                                </div>
                            ) : null}

                            {isAcknowledged ? (
                                <div className="flex items-center gap-3 rounded-xl border border-success/30 bg-success/5 p-4 text-success">
                                    <CheckCircle2 className="size-5 shrink-0" />
                                    <div>
                                        <p className="font-medium">Acknowledged</p>
                                        {announcement.acknowledged_at ? (
                                            <p className="text-xs opacity-80">
                                                {announcement.acknowledged_at}
                                            </p>
                                        ) : null}
                                    </div>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    {/* Sidebar: details + attachments */}
                    <div className="space-y-4">
                        {/* At a glance */}
                        <Card className="h-fit glass-card">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">At a glance</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-0 divide-y divide-border/50 text-sm">
                                <div className="flex items-start gap-3 py-3 first:pt-0">
                                    <CalendarDays className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Published
                                        </div>
                                        <div className="mt-0.5 font-medium">
                                            {announcement.published_at ?? '—'}
                                        </div>
                                    </div>
                                </div>
                                <div className="flex items-start gap-3 py-3">
                                    <Tag className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Category
                                        </div>
                                        <div className="mt-0.5 font-medium capitalize">
                                            {announcement.category}
                                        </div>
                                    </div>
                                </div>
                                <div className="flex items-start gap-3 py-3">
                                    <Clock className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Acknowledgement
                                        </div>
                                        <div
                                            className={cn(
                                                'mt-0.5 font-medium',
                                                announcement.requires_acknowledgement
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {announcement.requires_acknowledgement
                                                ? 'Required'
                                                : 'Not required'}
                                        </div>
                                    </div>
                                </div>
                                {isAcknowledged ? (
                                    <div className="py-3 last:pb-0">
                                        <div className="flex items-center gap-2 rounded-lg bg-success/10 px-3 py-2 text-sm text-success">
                                            <CheckCircle2 className="size-4" />
                                            You acknowledged this
                                        </div>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>

                        {/* Attachments */}
                        {announcement.attachments.length > 0 ? (
                            <Card className="glass-card">
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <FileText className="size-4 text-primary" />
                                        Attachments
                                        <Badge variant="secondary" className="ml-auto text-xs">
                                            {announcement.attachments.length}
                                        </Badge>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ul className="grid gap-2 text-sm">
                                        {announcement.attachments.map((attachment) => (
                                            <li
                                                key={attachment.id}
                                                className="flex items-center gap-2 rounded-lg border border-border/70 bg-muted/20 px-3 py-2.5 transition-colors hover:bg-muted/40"
                                            >
                                                <Download className="size-4 shrink-0 text-muted-foreground" />
                                                <span className="min-w-0 truncate">
                                                    {attachment.original_name}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </div>
            </Main>
        </>
    );
}
