import { Head } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarDays,
    Download,
    FileText,
    Megaphone,
    Tag,
} from 'lucide-react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

const priorityVariant = (priority: string): 'warning' | 'outline' => {
    if (priority === 'urgent' || priority === 'high') {
        return 'warning';
    }

    return 'outline';
};

export default function EmployeeAnnouncementShow({
    announcement,
}: {
    announcement: {
        title: string;
        body_html: string;
        priority: string;
        category: string;
        published_at: string | null;
        attachments: { id: number; original_name: string }[];
    };
    recipient_id: number;
}) {
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

                <div className="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
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
                        </CardContent>
                    </Card>

                    <div className="space-y-4">
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
                                <div className="flex items-start gap-3 py-3 last:pb-0">
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
                            </CardContent>
                        </Card>

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
