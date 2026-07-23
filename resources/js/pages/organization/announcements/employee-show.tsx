import { Head, router } from '@inertiajs/react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';

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
    return (
        <>
            <Head title={announcement.title} />
            <Main>
                <PageHeader
                    title={announcement.title}
                    description={`${announcement.category} · ${announcement.priority}`}
                />
                <div className="mx-auto max-w-3xl space-y-6 rounded-xl border p-6">
                    <div
                        className="prose prose-sm max-w-none dark:prose-invert"
                        dangerouslySetInnerHTML={{
                            __html: announcement.body_html,
                        }}
                    />
                    {announcement.attachments.length > 0 ? (
                        <ul className="text-sm">
                            {announcement.attachments.map((attachment) => (
                                <li key={attachment.id}>
                                    {attachment.original_name}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {can_acknowledge ? (
                        <Button
                            onClick={() =>
                                router.post(
                                    `/organization/announcements/inbox/${recipient_id}/acknowledge`,
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
            </Main>
        </>
    );
}
