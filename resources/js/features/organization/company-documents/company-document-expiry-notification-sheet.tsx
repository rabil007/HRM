import { useForm } from '@inertiajs/react';
import { Bell, BellOff, UserMinus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { update as updateNotificationSetting } from '@/actions/App/Http/Controllers/Organization/CompanyDocumentExpiryNotificationSettingController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import type {
    CompanyDocumentCompany,
    CompanyDocumentExpiryNotificationSetting,
    CompanyUser,
} from './types';

const fieldLabelClass =
    'text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase';

type NotificationFormData = {
    enabled: boolean;
    to_user_ids: number[];
    cc_user_ids: number[];
};

function RecipientSelector({
    label,
    selectedUsers,
    availableUsers,
    error,
    onAdd,
    onRemove,
}: {
    label: string;
    selectedUsers: CompanyUser[];
    availableUsers: CompanyUser[];
    error?: string;
    onAdd: (user: CompanyUser) => void;
    onRemove: (userId: number) => void;
}) {
    const selectedIds = new Set(selectedUsers.map((u) => u.id));
    const unselected = availableUsers.filter((u) => !selectedIds.has(u.id));

    return (
        <div className="space-y-3">
            <Label className={fieldLabelClass}>{label}</Label>

            {selectedUsers.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {selectedUsers.map((user) => (
                        <div
                            key={user.id}
                            className="flex items-center gap-1.5 rounded-xl border border-border/70 bg-muted/40 px-3 py-1.5 text-sm font-medium"
                        >
                            <span>{user.name}</span>
                            <button
                                type="button"
                                onClick={() => onRemove(user.id)}
                                className="ml-0.5 rounded-md text-muted-foreground transition-colors hover:text-destructive"
                                aria-label={`Remove ${user.name}`}
                            >
                                <X className="h-3.5 w-3.5" />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            {unselected.length > 0 && (
                <div className="rounded-xl border border-dashed border-border bg-muted/10 p-2">
                    <p className="mb-2 px-1 text-[11px] font-semibold tracking-wider text-muted-foreground/60 uppercase">
                        Add recipient
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                        {unselected.map((user) => (
                            <button
                                key={user.id}
                                type="button"
                                onClick={() => onAdd(user)}
                                className="rounded-lg border border-border/60 bg-card px-2.5 py-1 text-xs font-medium transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-primary"
                            >
                                + {user.name}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {selectedUsers.length === 0 && unselected.length === 0 && (
                <div className="flex items-center gap-2 rounded-xl border border-dashed p-3 text-xs text-muted-foreground">
                    <UserMinus className="h-4 w-4 shrink-0" />
                    <span>No active company members available to select.</span>
                </div>
            )}

            <InputError message={error} />
        </div>
    );
}

export function CompanyDocumentExpiryNotificationSheet({
    company,
    setting,
    companyUsers,
    open,
    onOpenChange,
}: {
    company: CompanyDocumentCompany;
    setting: CompanyDocumentExpiryNotificationSetting | null;
    companyUsers: CompanyUser[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm<NotificationFormData>({
        enabled: false,
        to_user_ids: [],
        cc_user_ids: [],
    });

    const [toUsers, setToUsers] = useState<CompanyUser[]>([]);
    const [ccUsers, setCcUsers] = useState<CompanyUser[]>([]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const currentSetting = setting ?? {
            enabled: false,
            to_recipients: [],
            cc_recipients: [],
        };

        const toSelected = currentSetting.to_recipients;
        const ccSelected = currentSetting.cc_recipients;

        setToUsers(toSelected);
        setCcUsers(ccSelected);

        form.clearErrors();
        form.setData({
            enabled: currentSetting.enabled,
            to_user_ids: toSelected.map((u) => u.id),
            cc_user_ids: ccSelected.map((u) => u.id),
        });
        // form is mutable; intentional omission
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, setting]);

    const handleAddTo = (user: CompanyUser) => {
        const updated = [...toUsers, user];
        setToUsers(updated);
        form.setData(
            'to_user_ids',
            updated.map((u) => u.id),
        );
    };

    const handleRemoveTo = (userId: number) => {
        const updated = toUsers.filter((u) => u.id !== userId);
        setToUsers(updated);
        form.setData(
            'to_user_ids',
            updated.map((u) => u.id),
        );
    };

    const handleAddCc = (user: CompanyUser) => {
        const updated = [...ccUsers, user];
        setCcUsers(updated);
        form.setData(
            'cc_user_ids',
            updated.map((u) => u.id),
        );
    };

    const handleRemoveCc = (userId: number) => {
        const updated = ccUsers.filter((u) => u.id !== userId);
        setCcUsers(updated);
        form.setData(
            'cc_user_ids',
            updated.map((u) => u.id),
        );
    };

    // Exclude users already selected in To from being selectable in CC, and vice versa.
    const toUserIds = new Set(toUsers.map((u) => u.id));
    const ccUserIds = new Set(ccUsers.map((u) => u.id));
    const availableForTo = companyUsers.filter((u) => !ccUserIds.has(u.id));
    const availableForCc = companyUsers.filter((u) => !toUserIds.has(u.id));

    const isIncomplete = form.data.enabled && toUsers.length === 0;

    const submit = () => {
        form.put(updateNotificationSetting.url(company.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        Company Document Expiry Notifications
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        Configure who receives expiry alerts for all company
                        documents belonging to {company.name}.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    {/* Enabled toggle */}
                    <div className="rounded-xl border border-border/60 bg-card p-5">
                        <div className="flex items-center justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <div
                                    className={cn(
                                        'flex h-9 w-9 items-center justify-center rounded-xl',
                                        form.data.enabled
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {form.data.enabled ? (
                                        <Bell className="h-4.5 w-4.5" />
                                    ) : (
                                        <BellOff className="h-4.5 w-4.5" />
                                    )}
                                </div>
                                <div>
                                    <p className="text-sm font-semibold">
                                        Automatic notifications
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {form.data.enabled
                                            ? 'Enabled — emails sent daily'
                                            : 'Disabled'}
                                    </p>
                                </div>
                            </div>
                            <Switch
                                checked={form.data.enabled}
                                onCheckedChange={(checked) =>
                                    form.setData('enabled', checked)
                                }
                            />
                        </div>
                    </div>

                    {/* Incomplete warning */}
                    {isIncomplete && (
                        <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-400">
                            <strong>No TO recipients configured.</strong> Add at
                            least one recipient before company document expiry
                            emails can be sent.
                        </div>
                    )}

                    {/* Recipients */}
                    <div className="space-y-6">
                        <RecipientSelector
                            label="Send To"
                            selectedUsers={toUsers}
                            availableUsers={availableForTo}
                            error={form.errors.to_user_ids}
                            onAdd={handleAddTo}
                            onRemove={handleRemoveTo}
                        />

                        <RecipientSelector
                            label="CC"
                            selectedUsers={ccUsers}
                            availableUsers={availableForCc}
                            error={form.errors.cc_user_ids}
                            onAdd={handleAddCc}
                            onRemove={handleRemoveCc}
                        />
                    </div>

                    {/* Scope note */}
                    <div className="rounded-xl border border-border/40 bg-muted/30 p-4 text-xs text-muted-foreground">
                        <p className="font-semibold text-foreground/80">
                            Applies to all company documents
                        </p>
                        <p className="mt-1 leading-relaxed">
                            These recipients receive expiry notifications for
                            every company document belonging to {company.name}{' '}
                            that has an expiry date.
                        </p>
                        <p className="mt-2 leading-relaxed">
                            Employee document expiry recipients are configured
                            separately under{' '}
                            <span className="font-medium">
                                Settings → Email Templates → Document expiry
                                alert
                            </span>
                            .
                        </p>
                    </div>
                </div>

                {/* Footer */}
                <div className="border-t border-border/60 bg-card/95 px-8 py-5 backdrop-blur-xl">
                    <div className="flex items-center justify-end gap-3">
                        <Button
                            variant="outline"
                            className="h-10 rounded-xl"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            className="h-10 rounded-xl shadow-sm shadow-primary/20"
                            onClick={submit}
                            disabled={form.processing}
                        >
                            Save settings
                        </Button>
                    </div>
                </div>
            </SheetContent>
        </Sheet>
    );
}
