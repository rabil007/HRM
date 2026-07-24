import { Form, Head, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    CheckCircle2,
    Download,
    FileText,
    Megaphone,
    Tag,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDisplayDateTime } from '@/lib/format-date';

type PublicAnnouncementAttachment = {
    id: number;
    original_name: string;
    download_url: string;
};

type Props = {
    company_name: string;
    announcement: {
        title: string;
        body_html: string;
        category: string;
        priority: string;
        published_at: string | null;
        requires_acknowledgement: boolean;
        attachments: PublicAnnouncementAttachment[];
    };
    acknowledged_at: string | null;
    acknowledge_url: string | null;
};

const priorityVariant = (priority: string): 'warning' | 'outline' => {
    const normalized = priority.toLowerCase();

    if (normalized === 'urgent' || normalized === 'high') {
        return 'warning';
    }

    return 'outline';
};

export default function PublicAnnouncementShow({
    company_name,
    announcement,
    acknowledged_at,
    acknowledge_url,
}: Props): ReactElement {
    const { flash } = usePage().props as {
        flash?: { success?: string };
    };

    return (
        <div className="min-h-svh bg-background text-foreground">
            <Head title={announcement.title} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-10 sm:px-6">
                <header className="space-y-3">
                    <p className="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                        {company_name}
                    </p>
                    <div className="space-y-3">
                        <h1 className="text-3xl font-semibold tracking-tight text-balance">
                            {announcement.title}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant={priorityVariant(announcement.priority)}
                            >
                                {announcement.priority} priority
                            </Badge>
                            <Badge variant="secondary">
                                {announcement.category}
                            </Badge>
                        </div>
                    </div>
                </header>

                {flash?.success ? (
                    <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                        {flash.success}
                    </div>
                ) : null}

                <Card className="overflow-hidden border-border/70 shadow-sm">
                    <CardHeader className="border-b border-border/60 bg-muted/20">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Megaphone className="size-4 text-primary" />
                            Announcement
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6 pt-6">
                        <div
                            className="prose prose-sm dark:prose-invert max-w-none leading-7"
                            dangerouslySetInnerHTML={{
                                __html: announcement.body_html,
                            }}
                        />
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card className="border-border/70 shadow-sm">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-0 divide-y divide-border/50 text-sm">
                            <div className="flex items-start gap-3 py-3 first:pt-0">
                                <CalendarDays className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Published
                                    </div>
                                    <div className="mt-0.5 font-medium">
                                        {announcement.published_at
                                            ? formatDisplayDateTime(
                                                  announcement.published_at,
                                              )
                                            : '—'}
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-start gap-3 py-3 last:pb-0">
                                <Tag className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Category
                                    </div>
                                    <div className="mt-0.5 font-medium">
                                        {announcement.category}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {announcement.attachments.length > 0 ? (
                        <Card className="border-border/70 shadow-sm">
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <FileText className="size-4 text-primary" />
                                    Attachments
                                    <Badge
                                        variant="secondary"
                                        className="ml-auto text-xs"
                                    >
                                        {announcement.attachments.length}
                                    </Badge>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul className="grid gap-2 text-sm">
                                    {announcement.attachments.map(
                                        (attachment) => (
                                            <li key={attachment.id}>
                                                <a
                                                    href={
                                                        attachment.download_url
                                                    }
                                                    className="flex items-center gap-2 rounded-lg border border-border/70 bg-muted/20 px-3 py-2.5 transition-colors hover:bg-muted/40"
                                                >
                                                    <Download className="size-4 shrink-0 text-muted-foreground" />
                                                    <span className="min-w-0 truncate text-primary">
                                                        {
                                                            attachment.original_name
                                                        }
                                                    </span>
                                                </a>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </CardContent>
                        </Card>
                    ) : null}
                </div>

                {announcement.requires_acknowledgement && acknowledge_url ? (
                    <Card className="border-border/70 shadow-sm">
                        <CardContent className="flex flex-col gap-3 pt-6 sm:flex-row sm:items-center sm:justify-between">
                            {acknowledged_at ? (
                                <div className="flex items-center gap-2 text-sm text-emerald-400">
                                    <CheckCircle2 className="size-4" />
                                    Acknowledged{' '}
                                    {formatDisplayDateTime(acknowledged_at)}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Please confirm you have read this
                                    announcement.
                                </p>
                            )}
                            {!acknowledged_at ? (
                                <Form
                                    action={acknowledge_url}
                                    method="post"
                                    className="shrink-0"
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Acknowledge
                                        </Button>
                                    )}
                                </Form>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : null}
            </div>
        </div>
    );
}
