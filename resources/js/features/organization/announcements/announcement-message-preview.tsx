import {
    Bell,
    Download,
    FileText,
    Mail,
    MessageCircle,
    Smartphone,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { AnnouncementChannelPreviews } from '@/features/organization/announcements/types';
import { WhatsAppDocumentTemplatePreview } from '@/features/settings/whatsapp-document-template-preview';
import { cn } from '@/lib/utils';

type PreviewChannel = 'in_app' | 'email' | 'whatsapp';

const CHANNEL_META: Record<
    PreviewChannel,
    { label: string; Icon: typeof Mail }
> = {
    in_app: { label: 'In-app', Icon: Smartphone },
    email: { label: 'Email', Icon: Mail },
    whatsapp: { label: 'WhatsApp', Icon: MessageCircle },
};

function defaultChannel(previews: AnnouncementChannelPreviews): PreviewChannel {
    const order: PreviewChannel[] = ['email', 'whatsapp', 'in_app'];

    for (const channel of order) {
        if (previews[channel] !== null && previews.channels.includes(channel)) {
            return channel;
        }
    }

    return 'in_app';
}

function InAppPreview({
    preview,
}: {
    preview: NonNullable<AnnouncementChannelPreviews['in_app']>;
}) {
    return (
        <div className="mx-auto w-full max-w-md space-y-3">
            <p className="text-xs font-bold tracking-widest text-muted-foreground uppercase">
                In-app notification
            </p>
            <div className="overflow-hidden rounded-xl border border-border/70 bg-card shadow-sm">
                <div className="flex items-start gap-3 border-b border-border/60 bg-muted/30 px-4 py-3">
                    <div className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary">
                        <Bell className="size-4" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="truncate text-sm font-semibold">
                                {preview.title}
                            </p>
                            <Badge variant="outline" className="text-[10px]">
                                {preview.priority_label}
                            </Badge>
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {preview.category_label} · just now
                        </p>
                    </div>
                </div>
                <div
                    className="prose prose-sm dark:prose-invert max-w-none px-4 py-4 leading-6"
                    dangerouslySetInnerHTML={{ __html: preview.body_html }}
                />
            </div>
        </div>
    );
}

function EmailPreview({
    preview,
}: {
    preview: NonNullable<AnnouncementChannelPreviews['email']>;
}) {
    return (
        <div className="space-y-3">
            <p className="text-xs font-bold tracking-widest text-muted-foreground uppercase">
                Email template
            </p>
            <div className="overflow-hidden rounded-xl border border-border/70 bg-[#f4f4f5] shadow-sm">
                <div className="space-y-1.5 border-b border-border/60 bg-card px-4 py-3">
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Mail className="size-3.5 shrink-0" />
                        <span className="truncate">
                            To: employee@company.com
                        </span>
                    </div>
                    <p className="truncate text-sm font-semibold text-foreground">
                        {preview.subject}
                    </p>
                </div>
                <iframe
                    title="Announcement email preview"
                    srcDoc={preview.html}
                    className="h-112 w-full border-0 bg-white"
                    sandbox=""
                />
            </div>
        </div>
    );
}

function WhatsAppPreview({
    preview,
}: {
    preview: NonNullable<AnnouncementChannelPreviews['whatsapp']>;
}) {
    return (
        <WhatsAppDocumentTemplatePreview
            templateName={preview.template_name}
            templateLanguage={preview.template_language}
            bodyText={preview.body_text}
            headerType="none"
            accountName={preview.company_name}
            className="mx-auto"
        />
    );
}

export function AnnouncementMessagePreview({
    previews,
    attachments,
    canDownloadAttachments = false,
    announcementId,
}: {
    previews: AnnouncementChannelPreviews;
    attachments: {
        id: number;
        original_name: string;
        mime_type: string;
        size_bytes: number;
    }[];
    canDownloadAttachments?: boolean;
    announcementId: number;
}) {
    const availableChannels = useMemo(
        () =>
            (['email', 'whatsapp', 'in_app'] as const).filter(
                (channel) =>
                    previews.channels.includes(channel) &&
                    previews[channel] !== null,
            ),
        [previews],
    );

    const [active, setActive] = useState<PreviewChannel>(() =>
        defaultChannel(previews),
    );

    const channel = availableChannels.includes(active)
        ? active
        : availableChannels[0];

    if (availableChannels.length === 0) {
        return (
            <div className="rounded-lg border border-dashed border-border/70 bg-muted/20 px-4 py-8 text-center text-sm text-muted-foreground">
                No delivery channels selected for this announcement.
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <Tabs
                value={channel}
                onValueChange={(value) => setActive(value as PreviewChannel)}
                className="gap-4"
            >
                <TabsList
                    className={cn(
                        'h-11 w-full justify-start gap-1 rounded-xl border border-border/40 bg-muted/40 p-1 sm:w-auto',
                        availableChannels.length === 1 && 'hidden',
                    )}
                >
                    {availableChannels.map((value) => {
                        const { label, Icon } = CHANNEL_META[value];

                        return (
                            <TabsTrigger
                                key={value}
                                value={value}
                                className="gap-1.5 px-3"
                            >
                                <Icon className="size-3.5" />
                                {label}
                            </TabsTrigger>
                        );
                    })}
                </TabsList>

                {previews.email ? (
                    <TabsContent value="email" className="mt-0">
                        <EmailPreview preview={previews.email} />
                    </TabsContent>
                ) : null}

                {previews.whatsapp ? (
                    <TabsContent value="whatsapp" className="mt-0">
                        <WhatsAppPreview preview={previews.whatsapp} />
                    </TabsContent>
                ) : null}

                {previews.in_app ? (
                    <TabsContent value="in_app" className="mt-0">
                        <InAppPreview preview={previews.in_app} />
                    </TabsContent>
                ) : null}
            </Tabs>

            {attachments.length > 0 ? (
                <div className="border-t border-border/60 pt-5">
                    <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                        <Download className="size-3.5 text-muted-foreground" />
                        Attachments ({attachments.length})
                    </h3>
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {attachments.map((attachment) => (
                            <li
                                key={attachment.id}
                                className="flex items-center gap-2 rounded-lg border border-border/70 bg-muted/30 px-3 py-2.5 text-sm transition-colors hover:bg-muted/60"
                            >
                                <FileText className="size-4 shrink-0 text-muted-foreground" />
                                <span className="min-w-0 truncate">
                                    {canDownloadAttachments ? (
                                        <a
                                            className="text-primary hover:underline"
                                            href={`/organization/announcements/${announcementId}/attachments/${attachment.id}/download`}
                                        >
                                            {attachment.original_name}
                                        </a>
                                    ) : (
                                        attachment.original_name
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}
