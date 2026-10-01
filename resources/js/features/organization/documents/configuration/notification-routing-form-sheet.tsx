import type { InertiaFormProps } from '@inertiajs/react';
import { AlertTriangle, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent, ReactElement } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import type {
    NotificationRoutingCompanyUser,
    NotificationRoutingDocumentTypeOption,
    NotificationRoutingFormData,
    NotificationRoutingRule,
} from '@/features/organization/documents/configuration/notification-routing-types';
import { cn } from '@/lib/utils';

const fieldLabelClass =
    'text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase';

function ChipList({
    items,
    onRemove,
}: {
    items: Array<{
        key: string;
        label: string;
        hint?: string;
        warning?: string;
    }>;
    onRemove: (key: string) => void;
}): ReactElement {
    if (items.length === 0) {
        return <p className="text-xs text-muted-foreground">None selected.</p>;
    }

    return (
        <div className="flex flex-wrap gap-2">
            {items.map((item) => (
                <div
                    key={item.key}
                    className={cn(
                        'flex max-w-full items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm font-medium',
                        item.warning
                            ? 'border-amber-500/40 bg-amber-500/10'
                            : 'border-border/70 bg-muted/40',
                    )}
                >
                    <div className="min-w-0">
                        <span className="block truncate">{item.label}</span>
                        {item.hint ? (
                            <span className="block truncate text-xs font-normal text-muted-foreground">
                                {item.hint}
                            </span>
                        ) : null}
                        {item.warning ? (
                            <span className="mt-0.5 block truncate text-xs font-medium text-amber-800 dark:text-amber-200">
                                {item.warning}
                            </span>
                        ) : null}
                    </div>
                    <button
                        type="button"
                        onClick={() => onRemove(item.key)}
                        className="ml-0.5 rounded-md text-muted-foreground transition-colors hover:text-destructive"
                        aria-label={`Remove ${item.label}`}
                    >
                        <X className="h-3.5 w-3.5" />
                    </button>
                </div>
            ))}
        </div>
    );
}

function SearchableAddList({
    options,
    emptyLabel,
    onAdd,
}: {
    options: Array<{ key: string; label: string; hint?: string }>;
    emptyLabel: string;
    onAdd: (key: string) => void;
}): ReactElement {
    const [query, setQuery] = useState('');

    const filtered = useMemo(() => {
        const normalized = query.trim().toLowerCase();

        if (normalized === '') {
            return options.slice(0, 8);
        }

        return options
            .filter((option) => {
                const haystack =
                    `${option.label} ${option.hint ?? ''}`.toLowerCase();

                return haystack.includes(normalized);
            })
            .slice(0, 8);
    }, [options, query]);

    return (
        <div className="space-y-2 rounded-xl border border-dashed border-border bg-muted/10 p-3">
            <Input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Search…"
                className="h-10 rounded-xl"
            />
            {filtered.length === 0 ? (
                <p className="text-xs text-muted-foreground">{emptyLabel}</p>
            ) : (
                <div className="flex flex-wrap gap-1.5">
                    {filtered.map((option) => (
                        <button
                            key={option.key}
                            type="button"
                            onClick={() => {
                                onAdd(option.key);
                                setQuery('');
                            }}
                            className="rounded-lg border border-border/60 bg-card px-2.5 py-1 text-xs font-medium transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-primary"
                        >
                            + {option.label}
                            {option.hint ? (
                                <span className="ml-1 text-muted-foreground">
                                    {option.hint}
                                </span>
                            ) : null}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function ManualEmailField({
    emails,
    error,
    onAdd,
    onRemove,
}: {
    emails: string[];
    error?: string;
    onAdd: (email: string) => void;
    onRemove: (email: string) => void;
}): ReactElement {
    const [value, setValue] = useState('');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const email = value.trim().toLowerCase();

        if (email === '') {
            return;
        }

        onAdd(email);
        setValue('');
    };

    return (
        <div className="space-y-3">
            <div className="flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 text-xs text-amber-950 dark:text-amber-100">
                <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <p>
                    External / Manual Email recipients are trusted addresses
                    configured by an administrator. They receive employee
                    document details without OMS-HRM login or visibility rules.
                </p>
            </div>

            <ChipList
                items={emails.map((email) => ({
                    key: email,
                    label: email,
                    hint: 'External',
                }))}
                onRemove={onRemove}
            />

            <form onSubmit={submit} className="flex gap-2">
                <Input
                    type="email"
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                    placeholder="compliance@example.com"
                    className="h-10 rounded-xl"
                />
                <Button type="submit" variant="outline" className="rounded-xl">
                    Add
                </Button>
            </form>
            <InputError message={error} />
        </div>
    );
}

export function NotificationRoutingFormSheet({
    open,
    onOpenChange,
    rule,
    form,
    documentTypes,
    companyUsers,
    canUpdate,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    rule: NotificationRoutingRule | null;
    form: InertiaFormProps<NotificationRoutingFormData>;
    documentTypes: NotificationRoutingDocumentTypeOption[];
    companyUsers: NotificationRoutingCompanyUser[];
    canUpdate: boolean;
    onSubmit: () => void;
}): ReactElement {
    const documentTypeLookup = useMemo(() => {
        const map = new Map<number, NotificationRoutingDocumentTypeOption>();

        for (const type of documentTypes) {
            map.set(type.id, type);
        }

        if (rule) {
            for (const type of rule.document_types) {
                if (!map.has(type.id)) {
                    map.set(type.id, type);
                }
            }
        }

        return map;
    }, [documentTypes, rule]);

    const companyUserLookup = useMemo(() => {
        const map = new Map<number, NotificationRoutingCompanyUser>();

        for (const user of companyUsers) {
            map.set(user.id, user);
        }

        if (rule) {
            for (const recipient of [...rule.to, ...rule.cc]) {
                if (
                    recipient.kind === 'user' &&
                    recipient.user_id &&
                    !map.has(recipient.user_id)
                ) {
                    map.set(recipient.user_id, {
                        id: recipient.user_id,
                        name: recipient.name ?? recipient.label,
                        email: recipient.email ?? '',
                        eligible: recipient.eligible,
                    });
                }
            }
        }

        return map;
    }, [companyUsers, rule]);

    const selectedDocumentTypes = form.data.document_type_ids
        .map((id) => documentTypeLookup.get(id))
        .filter((type): type is NotificationRoutingDocumentTypeOption =>
            Boolean(type),
        );
    const availableDocumentTypes = documentTypes.filter(
        (type) =>
            type.is_active && !form.data.document_type_ids.includes(type.id),
    );

    const selectedToUsers = form.data.to_user_ids
        .map((id) => companyUserLookup.get(id))
        .filter((user): user is NotificationRoutingCompanyUser =>
            Boolean(user),
        );
    const selectedCcUsers = form.data.cc_user_ids
        .map((id) => companyUserLookup.get(id))
        .filter((user): user is NotificationRoutingCompanyUser =>
            Boolean(user),
        );

    const availableToUsers = companyUsers.filter(
        (user) =>
            user.eligible &&
            !form.data.to_user_ids.includes(user.id) &&
            !form.data.cc_user_ids.includes(user.id),
    );
    const availableCcUsers = companyUsers.filter(
        (user) =>
            user.eligible &&
            !form.data.cc_user_ids.includes(user.id) &&
            !form.data.to_user_ids.includes(user.id),
    );

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {rule
                            ? 'Edit notification rule'
                            : 'New notification rule'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        Route employee document expiry alerts to specific
                        recipients by document type. Company and Branch document
                        expiry settings remain separate.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    <div className="space-y-2">
                        <Label htmlFor="rule_name" className={fieldLabelClass}>
                            Rule name
                        </Label>
                        <Input
                            id="rule_name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            disabled={!canUpdate}
                            className="h-11 rounded-xl"
                            placeholder="HR Identity Documents"
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="space-y-3">
                        <Label className={fieldLabelClass}>
                            Document types
                        </Label>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                disabled={!canUpdate}
                                onClick={() =>
                                    form.setData('all_document_types', true)
                                }
                                className={cn(
                                    'rounded-xl border px-3 py-2 text-sm font-medium transition-colors',
                                    form.data.all_document_types
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : 'border-border bg-card text-muted-foreground hover:border-primary/40',
                                )}
                            >
                                All document types
                            </button>
                            <button
                                type="button"
                                disabled={!canUpdate}
                                onClick={() =>
                                    form.setData('all_document_types', false)
                                }
                                className={cn(
                                    'rounded-xl border px-3 py-2 text-sm font-medium transition-colors',
                                    !form.data.all_document_types
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : 'border-border bg-card text-muted-foreground hover:border-primary/40',
                                )}
                            >
                                Selected document types
                            </button>
                        </div>

                        {!form.data.all_document_types ? (
                            <div className="space-y-3">
                                <ChipList
                                    items={selectedDocumentTypes.map(
                                        (type) => ({
                                            key: String(type.id),
                                            label: type.title,
                                            warning: type.is_active
                                                ? undefined
                                                : 'Inactive',
                                        }),
                                    )}
                                    onRemove={(key) =>
                                        form.setData(
                                            'document_type_ids',
                                            form.data.document_type_ids.filter(
                                                (id) => id !== Number(key),
                                            ),
                                        )
                                    }
                                />
                                <SearchableAddList
                                    options={availableDocumentTypes.map(
                                        (type) => ({
                                            key: String(type.id),
                                            label: type.title,
                                        }),
                                    )}
                                    emptyLabel="No matching document types."
                                    onAdd={(key) =>
                                        form.setData('document_type_ids', [
                                            ...form.data.document_type_ids,
                                            Number(key),
                                        ])
                                    }
                                />
                                <InputError
                                    message={form.errors.document_type_ids}
                                />
                            </div>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                This rule applies to every current and future
                                employee document type.
                            </p>
                        )}
                    </div>

                    <div className="space-y-4 rounded-2xl border border-border/70 bg-muted/10 p-4">
                        <div>
                            <Label className={fieldLabelClass}>
                                TO recipients
                            </Label>
                            <p className="mt-1 text-xs text-muted-foreground">
                                At least one TO recipient is required when the
                                rule is enabled.
                            </p>
                        </div>

                        <div className="space-y-2">
                            <p className="text-xs font-semibold text-muted-foreground">
                                Users
                            </p>
                            <ChipList
                                items={[
                                    ...selectedToUsers.map((user) => ({
                                        key: `user:${user.id}`,
                                        label: user.name,
                                        hint: user.email || undefined,
                                        warning: user.eligible
                                            ? undefined
                                            : 'No longer eligible',
                                    })),
                                    ...form.data.to_orphaned_recipient_ids.map(
                                        (recipientId) => ({
                                            key: `orphan:${recipientId}`,
                                            label: 'Deleted / unavailable user',
                                            warning: 'Will not receive email',
                                        }),
                                    ),
                                ]}
                                onRemove={(key) => {
                                    if (key.startsWith('orphan:')) {
                                        const recipientId = Number(
                                            key.slice('orphan:'.length),
                                        );
                                        form.setData(
                                            'to_orphaned_recipient_ids',
                                            form.data.to_orphaned_recipient_ids.filter(
                                                (id) => id !== recipientId,
                                            ),
                                        );

                                        return;
                                    }

                                    const userId = Number(
                                        key.slice('user:'.length),
                                    );
                                    form.setData(
                                        'to_user_ids',
                                        form.data.to_user_ids.filter(
                                            (id) => id !== userId,
                                        ),
                                    );
                                }}
                            />
                            <SearchableAddList
                                options={availableToUsers.map((user) => ({
                                    key: String(user.id),
                                    label: user.name,
                                    hint: user.email,
                                }))}
                                emptyLabel="No matching company users."
                                onAdd={(key) =>
                                    form.setData('to_user_ids', [
                                        ...form.data.to_user_ids,
                                        Number(key),
                                    ])
                                }
                            />
                            <InputError message={form.errors.to_user_ids} />
                        </div>

                        <div className="space-y-2">
                            <p className="text-xs font-semibold text-muted-foreground">
                                Manual emails
                            </p>
                            <ManualEmailField
                                emails={form.data.to_emails}
                                error={form.errors.to_emails}
                                onAdd={(email) => {
                                    if (
                                        form.data.to_emails.includes(email) ||
                                        form.data.cc_emails.includes(email)
                                    ) {
                                        return;
                                    }

                                    form.setData('to_emails', [
                                        ...form.data.to_emails,
                                        email,
                                    ]);
                                }}
                                onRemove={(email) =>
                                    form.setData(
                                        'to_emails',
                                        form.data.to_emails.filter(
                                            (item) => item !== email,
                                        ),
                                    )
                                }
                            />
                        </div>
                    </div>

                    <div className="space-y-4 rounded-2xl border border-border/70 bg-muted/10 p-4">
                        <div>
                            <Label className={fieldLabelClass}>
                                CC recipients
                            </Label>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Optional. Restricted internal users receive only
                                the employees they are allowed to see.
                            </p>
                        </div>

                        <div className="space-y-2">
                            <p className="text-xs font-semibold text-muted-foreground">
                                Users
                            </p>
                            <ChipList
                                items={[
                                    ...selectedCcUsers.map((user) => ({
                                        key: `user:${user.id}`,
                                        label: user.name,
                                        hint: user.email || undefined,
                                        warning: user.eligible
                                            ? undefined
                                            : 'No longer eligible',
                                    })),
                                    ...form.data.cc_orphaned_recipient_ids.map(
                                        (recipientId) => ({
                                            key: `orphan:${recipientId}`,
                                            label: 'Deleted / unavailable user',
                                            warning: 'Will not receive email',
                                        }),
                                    ),
                                ]}
                                onRemove={(key) => {
                                    if (key.startsWith('orphan:')) {
                                        const recipientId = Number(
                                            key.slice('orphan:'.length),
                                        );
                                        form.setData(
                                            'cc_orphaned_recipient_ids',
                                            form.data.cc_orphaned_recipient_ids.filter(
                                                (id) => id !== recipientId,
                                            ),
                                        );

                                        return;
                                    }

                                    const userId = Number(
                                        key.slice('user:'.length),
                                    );
                                    form.setData(
                                        'cc_user_ids',
                                        form.data.cc_user_ids.filter(
                                            (id) => id !== userId,
                                        ),
                                    );
                                }}
                            />
                            <SearchableAddList
                                options={availableCcUsers.map((user) => ({
                                    key: String(user.id),
                                    label: user.name,
                                    hint: user.email,
                                }))}
                                emptyLabel="No matching company users."
                                onAdd={(key) =>
                                    form.setData('cc_user_ids', [
                                        ...form.data.cc_user_ids,
                                        Number(key),
                                    ])
                                }
                            />
                            <InputError message={form.errors.cc_user_ids} />
                        </div>

                        <div className="space-y-2">
                            <p className="text-xs font-semibold text-muted-foreground">
                                Manual emails
                            </p>
                            <ManualEmailField
                                emails={form.data.cc_emails}
                                error={form.errors.cc_emails}
                                onAdd={(email) => {
                                    if (
                                        form.data.to_emails.includes(email) ||
                                        form.data.cc_emails.includes(email)
                                    ) {
                                        return;
                                    }

                                    form.setData('cc_emails', [
                                        ...form.data.cc_emails,
                                        email,
                                    ]);
                                }}
                                onRemove={(email) =>
                                    form.setData(
                                        'cc_emails',
                                        form.data.cc_emails.filter(
                                            (item) => item !== email,
                                        ),
                                    )
                                }
                            />
                        </div>
                    </div>
                    <div className="flex items-center justify-between rounded-xl border border-border/70 bg-muted/20 px-4 py-3">
                        <div>
                            <p className="text-sm font-medium">Enabled</p>
                            <p className="text-xs text-muted-foreground">
                                Disabled rules are kept but do not send alerts.
                            </p>
                        </div>
                        <Switch
                            checked={form.data.enabled}
                            disabled={!canUpdate}
                            onCheckedChange={(checked) =>
                                form.setData('enabled', checked)
                            }
                        />
                    </div>
                    <InputError message={form.errors.enabled} />
                </div>

                <div className="flex items-center justify-end gap-3 border-t border-border/60 p-6">
                    <Button
                        type="button"
                        variant="outline"
                        className="rounded-xl"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    {canUpdate ? (
                        <Button
                            type="button"
                            className="rounded-xl"
                            disabled={form.processing}
                            onClick={onSubmit}
                        >
                            Save
                        </Button>
                    ) : null}
                </div>
            </SheetContent>
        </Sheet>
    );
}
