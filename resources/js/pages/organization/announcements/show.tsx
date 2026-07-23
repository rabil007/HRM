import { Head, Link, router } from '@inertiajs/react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    AnnouncementCan,
    AnnouncementShow,
} from '@/features/organization/announcements/types';

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
                    description={`${announcement.status_label} · ${announcement.priority_label}`}
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

                <div className="mt-6 grid gap-6 lg:grid-cols-2">
                    <section className="space-y-3 rounded-xl border p-6">
                        <h2 className="text-lg font-semibold">Overview</h2>
                        <dl className="space-y-2 text-sm">
                            <div>
                                <dt className="text-muted-foreground">
                                    Category
                                </dt>
                                <dd>{announcement.category_label}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Priority
                                </dt>
                                <dd>{announcement.priority_label}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Status</dt>
                                <dd>{announcement.status_label}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Audience
                                </dt>
                                <dd>{announcement.audience_summary}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Channels
                                </dt>
                                <dd>{announcement.channels.join(', ')}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Created by
                                </dt>
                                <dd>{announcement.created_by ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Published by
                                </dt>
                                <dd>{announcement.published_by ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Published / scheduled
                                </dt>
                                <dd>
                                    {announcement.published_at ??
                                        announcement.scheduled_at ??
                                        '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Expiry
                                </dt>
                                <dd>{announcement.expires_at ?? '—'}</dd>
                            </div>
                        </dl>
                        <div
                            className="prose prose-sm max-w-none dark:prose-invert"
                            dangerouslySetInnerHTML={{
                                __html: announcement.body_html,
                            }}
                        />
                        {announcement.attachments.length > 0 ? (
                            <ul className="space-y-1 text-sm">
                                {announcement.attachments.map((attachment) => (
                                    <li key={attachment.id}>
                                        {can.download_attachments ? (
                                            <a
                                                className="text-primary underline"
                                                href={`/organization/announcements/${announcement.id}/attachments/${attachment.id}/download`}
                                            >
                                                {attachment.original_name}
                                            </a>
                                        ) : (
                                            attachment.original_name
                                        )}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </section>

                    <section className="space-y-3 rounded-xl border p-6">
                        <h2 className="text-lg font-semibold">
                            Recipient status
                        </h2>
                        <div className="space-y-1 text-sm leading-7">
                            <div>
                                Total recipients:{' '}
                                {announcement.delivery_summary.total_recipients}
                            </div>
                            <div>
                                In-app sent:{' '}
                                {announcement.delivery_summary.in_app_sent}
                            </div>
                            <div>
                                Email sent:{' '}
                                {announcement.delivery_summary.email_sent}
                            </div>
                            <div>
                                WhatsApp sent:{' '}
                                {announcement.delivery_summary.whatsapp_sent}
                            </div>
                            <div>
                                Failed: {announcement.delivery_summary.failed}
                            </div>
                            <div>
                                Skipped: {announcement.delivery_summary.skipped}
                            </div>
                            <div>
                                Acknowledged:{' '}
                                {announcement.delivery_summary.acknowledged}
                            </div>
                        </div>
                    </section>
                </div>

                {announcement.recipients.length > 0 ? (
                    <section className="mt-6 rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Department</TableHead>
                                    <TableHead>In-app</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>WhatsApp</TableHead>
                                    <TableHead>Read</TableHead>
                                    <TableHead>Acknowledged</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {announcement.recipients.map((recipient) => (
                                    <TableRow key={recipient.id}>
                                        <TableCell>
                                            {recipient.employee_name}
                                        </TableCell>
                                        <TableCell>
                                            {recipient.department ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {recipient.in_app ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {recipient.email ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {recipient.whatsapp ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {recipient.read_at ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {recipient.acknowledged_at ?? '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </section>
                ) : null}
            </Main>
        </>
    );
}
