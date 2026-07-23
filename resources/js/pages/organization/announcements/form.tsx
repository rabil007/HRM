import { Head, router, useForm, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type {
    AnnouncementCan,
    AnnouncementFormData,
    AnnouncementFormOptions,
    AnnouncementFormPayload,
    RecipientPreview,
} from '@/features/organization/announcements/types';

const CHANNELS = [
    { value: 'in_app', label: 'In-app' },
    { value: 'email', label: 'Email' },
    { value: 'whatsapp', label: 'WhatsApp' },
] as const;

export default function AnnouncementFormPage({
    announcement,
    options,
}: {
    announcement: AnnouncementFormPayload | null;
    options: AnnouncementFormOptions;
    can: AnnouncementCan;
}) {
    const isEdit = announcement !== null;
    const http = useHttp<{
        channels: string[];
        audiences: { type: string; id: number | null }[];
    }>({
        channels: ['in_app'],
        audiences: [{ type: 'all_employees', id: null }],
    });
    const [preview, setPreview] = useState<RecipientPreview | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [audienceMode, setAudienceMode] = useState<string>(
        announcement?.audiences.some((a) => a.type === 'all_employees')
            ? 'all_employees'
            : (announcement?.audiences[0]?.type ?? 'all_employees'),
    );

    const form = useForm<AnnouncementFormData>({
        title: announcement?.title ?? '',
        body_html: announcement?.body_html ?? '',
        category: announcement?.category ?? 'general',
        priority: announcement?.priority ?? 'normal',
        channels: announcement?.channels ?? ['in_app'],
        audiences: announcement?.audiences?.length
            ? announcement.audiences
            : [{ type: 'all_employees', id: null }],
        expires_at: announcement?.expires_at ?? '',
        requires_acknowledgement:
            announcement?.requires_acknowledgement ?? false,
        publish_mode:
            announcement?.status === 'scheduled' ? 'schedule' : 'draft',
        scheduled_at: announcement?.scheduled_at ?? '',
    });

    const toggleChannel = (channel: string, checked: boolean) => {
        const next = checked
            ? [...form.data.channels, channel]
            : form.data.channels.filter((c) => c !== channel);
        form.setData('channels', next);
    };

    const setAudienceType = (type: string) => {
        setAudienceMode(type);

        if (type === 'all_employees') {
            form.setData('audiences', [{ type: 'all_employees', id: null }]);

            return;
        }

        form.setData('audiences', []);
    };

    const toggleAudienceId = (type: string, id: number, checked: boolean) => {
        const current = form.data.audiences.filter((a) => a.type === type);
        const next = checked
            ? [...current, { type, id }]
            : current.filter((a) => a.id !== id);
        form.setData('audiences', next);
    };

    const loadPreview = () => {
        setPreviewLoading(true);
        http.setData({
            channels: form.data.channels,
            audiences: form.data.audiences,
        });
        http.post('/organization/announcements/preview-recipients')
            .then((data) => {
                setPreview(data as RecipientPreview);
            })
            .finally(() => setPreviewLoading(false));
    };

    const submit = (mode: AnnouncementFormData['publish_mode']) => {
        const payload = { ...form.data, publish_mode: mode };

        if (isEdit && announcement) {
            router.put(`/organization/announcements/${announcement.id}`, payload);

            return;
        }

        router.post('/organization/announcements', payload);
    };

    const selectedIds = (type: string) =>
        form.data.audiences
            .filter((a) => a.type === type)
            .map((a) => a.id)
            .filter((id): id is number => id !== null);

    return (
        <>
            <Head title={isEdit ? 'Edit announcement' : 'Create announcement'} />
            <Main>
                <PageHeader
                    title={isEdit ? 'Edit announcement' : 'Create announcement'}
                    description="Compose the message, choose audience and channels, then draft, schedule, or send."
                />

                <div className="mx-auto max-w-4xl space-y-8">
                    <section className="space-y-4 rounded-xl border p-6">
                        <h2 className="text-lg font-semibold">Message</h2>
                        <div className="space-y-2">
                            <Label htmlFor="title">Title</Label>
                            <Input
                                id="title"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                            />
                            <InputError message={form.errors.title} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="body_html">Message</Label>
                            <Textarea
                                id="body_html"
                                rows={8}
                                value={form.data.body_html}
                                onChange={(e) =>
                                    form.setData('body_html', e.target.value)
                                }
                            />
                            <InputError message={form.errors.body_html} />
                        </div>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Category</Label>
                                <Select
                                    value={form.data.category}
                                    onValueChange={(value) =>
                                        form.setData('category', value)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.categories.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label>Priority</Label>
                                <Select
                                    value={form.data.priority}
                                    onValueChange={(value) =>
                                        form.setData('priority', value)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.priorities.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="expires_at">Expiry date</Label>
                            <Input
                                id="expires_at"
                                type="datetime-local"
                                value={form.data.expires_at}
                                onChange={(e) =>
                                    form.setData('expires_at', e.target.value)
                                }
                            />
                        </div>
                        <div className="flex items-center gap-3">
                            <Switch
                                checked={form.data.requires_acknowledgement}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'requires_acknowledgement',
                                        checked,
                                    )
                                }
                            />
                            <Label>Require acknowledgement</Label>
                        </div>
                    </section>

                    <section className="space-y-4 rounded-xl border p-6">
                        <h2 className="text-lg font-semibold">Audience</h2>
                        <Select
                            value={audienceMode}
                            onValueChange={setAudienceType}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all_employees">
                                    All active employees
                                </SelectItem>
                                <SelectItem value="department">
                                    Selected departments
                                </SelectItem>
                                <SelectItem value="branch">
                                    Selected branches
                                </SelectItem>
                                <SelectItem value="position">
                                    Selected positions
                                </SelectItem>
                                <SelectItem value="employee">
                                    Selected employees
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        {audienceMode === 'department' ? (
                            <div className="grid max-h-48 gap-2 overflow-y-auto rounded-lg border p-3">
                                {options.departments.map((item) => (
                                    <label
                                        key={item.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={selectedIds(
                                                'department',
                                            ).includes(item.id)}
                                            onCheckedChange={(checked) =>
                                                toggleAudienceId(
                                                    'department',
                                                    item.id,
                                                    Boolean(checked),
                                                )
                                            }
                                        />
                                        {item.name}
                                    </label>
                                ))}
                            </div>
                        ) : null}
                        {audienceMode === 'branch' ? (
                            <div className="grid max-h-48 gap-2 overflow-y-auto rounded-lg border p-3">
                                {options.branches.map((item) => (
                                    <label
                                        key={item.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={selectedIds(
                                                'branch',
                                            ).includes(item.id)}
                                            onCheckedChange={(checked) =>
                                                toggleAudienceId(
                                                    'branch',
                                                    item.id,
                                                    Boolean(checked),
                                                )
                                            }
                                        />
                                        {item.name}
                                    </label>
                                ))}
                            </div>
                        ) : null}
                        {audienceMode === 'position' ? (
                            <div className="grid max-h-48 gap-2 overflow-y-auto rounded-lg border p-3">
                                {options.positions.map((item) => (
                                    <label
                                        key={item.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={selectedIds(
                                                'position',
                                            ).includes(item.id)}
                                            onCheckedChange={(checked) =>
                                                toggleAudienceId(
                                                    'position',
                                                    item.id,
                                                    Boolean(checked),
                                                )
                                            }
                                        />
                                        {item.name}
                                    </label>
                                ))}
                            </div>
                        ) : null}
                        {audienceMode === 'employee' ? (
                            <div className="grid max-h-48 gap-2 overflow-y-auto rounded-lg border p-3">
                                {options.employees.map((item) => (
                                    <label
                                        key={item.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={selectedIds(
                                                'employee',
                                            ).includes(item.id)}
                                            onCheckedChange={(checked) =>
                                                toggleAudienceId(
                                                    'employee',
                                                    item.id,
                                                    Boolean(checked),
                                                )
                                            }
                                        />
                                        {item.name}
                                        {item.employee_no
                                            ? ` (${item.employee_no})`
                                            : ''}
                                    </label>
                                ))}
                            </div>
                        ) : null}
                        <InputError message={form.errors.audiences} />
                    </section>

                    <section className="space-y-4 rounded-xl border p-6">
                        <h2 className="text-lg font-semibold">
                            Delivery channels
                        </h2>
                        <div className="flex flex-wrap gap-4">
                            {CHANNELS.map((channel) => (
                                <label
                                    key={channel.value}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        checked={form.data.channels.includes(
                                            channel.value,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggleChannel(
                                                channel.value,
                                                Boolean(checked),
                                            )
                                        }
                                    />
                                    {channel.label}
                                </label>
                            ))}
                        </div>
                        <InputError message={form.errors.channels} />
                    </section>

                    {isEdit && announcement ? (
                        <section className="space-y-4 rounded-xl border p-6">
                            <h2 className="text-lg font-semibold">
                                Attachments
                            </h2>
                            <ul className="space-y-2 text-sm">
                                {announcement.attachments.map((attachment) => (
                                    <li
                                        key={attachment.id}
                                        className="flex items-center justify-between rounded-lg border px-3 py-2"
                                    >
                                        <span>{attachment.original_name}</span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.delete(
                                                    `/organization/announcements/${announcement.id}/attachments/${attachment.id}`,
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                            <Input
                                type="file"
                                onChange={(e) => {
                                    const file = e.target.files?.[0];

                                    if (!file) {
                                        return;
                                    }

                                    const data = new FormData();
                                    data.append('attachment', file);
                                    router.post(
                                        `/organization/announcements/${announcement.id}/attachments`,
                                        data,
                                        { forceFormData: true },
                                    );
                                }}
                            />
                        </section>
                    ) : null}

                    <section className="space-y-4 rounded-xl border p-6">
                        <h2 className="text-lg font-semibold">Publishing</h2>
                        <div className="space-y-2">
                            <Label htmlFor="scheduled_at">
                                Schedule for later
                            </Label>
                            <Input
                                id="scheduled_at"
                                type="datetime-local"
                                value={form.data.scheduled_at}
                                onChange={(e) =>
                                    form.setData('scheduled_at', e.target.value)
                                }
                            />
                            <InputError message={form.errors.scheduled_at} />
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={loadPreview}
                            disabled={previewLoading}
                        >
                            {previewLoading
                                ? 'Loading preview...'
                                : 'Preview recipients'}
                        </Button>
                        {preview ? (
                            <div className="rounded-lg bg-muted/40 p-4 text-sm leading-7">
                                <div>
                                    Selected employees:{' '}
                                    {preview.selected_employees}
                                </div>
                                <div>
                                    In-app available: {preview.in_app_available}
                                </div>
                                <div>
                                    Email available: {preview.email_available}
                                </div>
                                <div>
                                    WhatsApp available:{' '}
                                    {preview.whatsapp_available}
                                </div>
                                <div>Missing email: {preview.missing_email}</div>
                                <div>Missing phone: {preview.missing_phone}</div>
                            </div>
                        ) : null}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                                onClick={() => submit('draft')}
                            >
                                Save as draft
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                                onClick={() => submit('schedule')}
                            >
                                Schedule
                            </Button>
                            <Button
                                type="button"
                                disabled={form.processing}
                                onClick={() => submit('send_now')}
                            >
                                Send now
                            </Button>
                        </div>
                    </section>
                </div>
            </Main>
        </>
    );
}
