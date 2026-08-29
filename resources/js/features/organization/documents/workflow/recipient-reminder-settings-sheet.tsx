import { useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useEffect } from 'react';
import DocumentRecipientAutomationSettingController from '@/actions/App/Http/Controllers/Organization/Documents/DocumentRecipientAutomationSettingController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import type { RecipientAutomationSettings } from '@/features/organization/documents/workflow/types';

type FormData = {
    reminders_enabled: boolean;
    reminder_days_before_expiry: number[];
};

export function RecipientReminderSettingsSheet({
    open,
    onOpenChange,
    settings,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    settings: RecipientAutomationSettings;
}) {
    const form = useForm<FormData>({
        reminders_enabled: settings.reminders_enabled,
        reminder_days_before_expiry: [...settings.reminder_days_before_expiry],
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        form.setData({
            reminders_enabled: settings.reminders_enabled,
            reminder_days_before_expiry: [
                ...settings.reminder_days_before_expiry,
            ],
        });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, settings]);

    const days = form.data.reminder_days_before_expiry;

    const addDay = () => {
        if (days.length >= 5) {
            return;
        }

        const candidate = [7, 3, 1, 5, 2].find((d) => !days.includes(d)) ?? 1;
        form.setData(
            'reminder_days_before_expiry',
            [...days, candidate].sort((a, b) => b - a),
        );
    };

    const removeDay = (day: number) => {
        form.setData(
            'reminder_days_before_expiry',
            days.filter((value) => value !== day),
        );
    };

    const updateDay = (index: number, value: string) => {
        const parsed = Number.parseInt(value, 10);
        const next = [...days];
        next[index] = Number.isFinite(parsed) ? parsed : 1;
        form.setData(
            'reminder_days_before_expiry',
            next.sort((a, b) => b - a),
        );
    };

    const submit = () => {
        if (!settings.can_update) {
            return;
        }

        form.put(DocumentRecipientAutomationSettingController.update.url(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>Reminder settings</SheetTitle>
                    <SheetDescription>
                        Recipient requests currently expire after{' '}
                        {settings.request_expiry_days} days. Reminder settings
                        apply to new requests only.
                    </SheetDescription>
                </SheetHeader>

                <div className="mt-6 space-y-6">
                    <div className="flex items-center justify-between gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="reminders_enabled">
                                Automatic reminders
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Queue reminder emails before expiry for new
                                requests.
                            </p>
                        </div>
                        <Switch
                            id="reminders_enabled"
                            checked={form.data.reminders_enabled}
                            disabled={!settings.can_update || form.processing}
                            onCheckedChange={(checked) =>
                                form.setData('reminders_enabled', checked)
                            }
                        />
                    </div>
                    <InputError message={form.errors.reminders_enabled} />

                    <div className="space-y-3">
                        <Label>Reminder days before expiry</Label>
                        <div className="flex flex-wrap gap-2">
                            {days.map((day, index) => (
                                <div
                                    key={`${day}-${index}`}
                                    className="flex items-center gap-1 rounded-md border px-2 py-1"
                                >
                                    <input
                                        type="number"
                                        min={1}
                                        max={13}
                                        value={day}
                                        disabled={
                                            !settings.can_update ||
                                            form.processing ||
                                            !form.data.reminders_enabled
                                        }
                                        onChange={(event) =>
                                            updateDay(index, event.target.value)
                                        }
                                        className="w-12 bg-transparent text-sm outline-none"
                                    />
                                    {settings.can_update &&
                                    form.data.reminders_enabled ? (
                                        <button
                                            type="button"
                                            onClick={() => removeDay(day)}
                                            className="text-muted-foreground hover:text-foreground"
                                            aria-label={`Remove ${day}-day reminder`}
                                        >
                                            <X className="h-3.5 w-3.5" />
                                        </button>
                                    ) : null}
                                </div>
                            ))}
                            {settings.can_update &&
                            form.data.reminders_enabled &&
                            days.length < 5 ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addDay}
                                >
                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                    Add
                                </Button>
                            ) : null}
                        </div>
                        <InputError
                            message={
                                form.errors.reminder_days_before_expiry ??
                                form.errors['reminder_days_before_expiry.0']
                            }
                        />
                    </div>
                </div>

                {settings.can_update ? (
                    <SheetFooter className="mt-8">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={form.processing}
                        >
                            Save
                        </Button>
                    </SheetFooter>
                ) : null}
            </SheetContent>
        </Sheet>
    );
}
