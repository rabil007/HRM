import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

export default function PublicAnnouncementShow({
    announcement,
    token,
    can_acknowledge,
}: {
    announcement: {
        title: string;
        body_html: string;
        priority: string;
        category: string;
        published_at: string | null;
        expires_at: string | null;
        requires_acknowledgement: boolean;
        acknowledged_at: string | null;
        attachments: {
            id: number;
            original_name: string;
            download_url: string;
        }[];
    };
    token: string;
    can_acknowledge: boolean;
}) {
    return (
        <div className="min-h-screen bg-muted/30 px-4 py-10">
            <Head title={announcement.title} />
            <div className="mx-auto max-w-2xl space-y-6 rounded-2xl border bg-background p-8 shadow-sm">
                <div>
                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                        {announcement.category} · {announcement.priority}
                    </p>
                    <h1 className="mt-2 text-2xl font-semibold">
                        {announcement.title}
                    </h1>
                </div>
                <div
                    className="prose prose-sm max-w-none dark:prose-invert"
                    dangerouslySetInnerHTML={{
                        __html: announcement.body_html,
                    }}
                />
                {announcement.attachments.length > 0 ? (
                    <ul className="space-y-2 text-sm">
                        {announcement.attachments.map((attachment) => (
                            <li key={attachment.id}>
                                <a
                                    className="text-primary underline"
                                    href={attachment.download_url}
                                >
                                    {attachment.original_name}
                                </a>
                            </li>
                        ))}
                    </ul>
                ) : null}
                {can_acknowledge ? (
                    <Button
                        onClick={() =>
                            router.post(
                                `/announcements/public/${token}/acknowledge`,
                            )
                        }
                    >
                        Acknowledge
                    </Button>
                ) : null}
                {announcement.acknowledged_at ? (
                    <p className="text-sm text-muted-foreground">
                        Acknowledged
                    </p>
                ) : null}
            </div>
        </div>
    );
}
