import { router, useForm } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { Textarea } from '@/components/ui/textarea';
import type {
    SigningPresetFormOptions,
    SigningPresetSummary,
} from '@/features/organization/documents/signing/types';
import { store, update } from '@/routes/organization/documents/signing-presets';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    preset: SigningPresetSummary | null;
    formOptions: SigningPresetFormOptions;
};

type FormData = {
    name: string;
    description: string;
    require_manager: boolean;
    require_company_signatory: boolean;
    company_signatory_user_id: string;
};

function buildSteps(data: FormData): Array<{
    recipient_role: string;
    target_user_id?: number;
}> {
    const steps: Array<{
        recipient_role: string;
        target_user_id?: number;
    }> = [{ recipient_role: 'subject' }];

    if (data.require_manager) {
        steps.push({ recipient_role: 'manager' });
    }

    if (data.require_company_signatory) {
        steps.push({
            recipient_role: 'company_signatory',
            target_user_id: Number(data.company_signatory_user_id),
        });
    }

    return steps;
}

export function SigningPresetFormDialog({
    open,
    onOpenChange,
    preset,
    formOptions,
}: Props) {
    const form = useForm<FormData>({
        name: '',
        description: '',
        require_manager: false,
        require_company_signatory: false,
        company_signatory_user_id: '',
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        const roles = new Set(preset?.steps.map((step) => step.recipient_role));
        const companyStep = preset?.steps.find(
            (step) => step.recipient_role === 'company_signatory',
        );

        form.setData({
            name: preset?.name ?? '',
            description: preset?.description ?? '',
            require_manager: roles.has('manager'),
            require_company_signatory: roles.has('company_signatory'),
            company_signatory_user_id: companyStep?.target_user_id
                ? String(companyStep.target_user_id)
                : '',
        });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when dialog opens/preset changes
    }, [open, preset?.id]);

    const sequencePreview = useMemo(() => {
        const parts = ['Employee'];

        if (form.data.require_manager) {
            parts.push('Department Manager');
        }

        if (form.data.require_company_signatory) {
            parts.push('Company Signatory');
        }

        return parts.join(' → ');
    }, [form.data.require_manager, form.data.require_company_signatory]);

    function submit() {
        const payload = {
            name: form.data.name,
            description: form.data.description || null,
            steps: buildSteps(form.data),
        };

        if (preset) {
            router.put(update.url(preset.id), payload, {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });

            return;
        }

        router.post(store.url(), payload, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>
                        {preset ? 'Edit signing preset' : 'New signing preset'}
                    </SheetTitle>
                    <SheetDescription>
                        Configure the sequential signing roles for this company.
                    </SheetDescription>
                </SheetHeader>

                <div className="mt-6 space-y-5">
                    <div className="space-y-2">
                        <Label htmlFor="signing-preset-name">Name</Label>
                        <Input
                            id="signing-preset-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        {form.errors.name ? (
                            <p className="text-sm text-destructive">
                                {form.errors.name}
                            </p>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="signing-preset-description">
                            Description
                        </Label>
                        <Textarea
                            id="signing-preset-description"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </div>

                    <div className="rounded-lg border border-border/70 p-4 text-sm">
                        Employee Signature is always required as step 1.
                    </div>

                    <div className="flex items-center justify-between gap-4">
                        <div>
                            <p className="font-medium">
                                Require Department Manager
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Resolved from the employee management hierarchy
                                at flow start.
                            </p>
                        </div>
                        <Switch
                            checked={form.data.require_manager}
                            onCheckedChange={(checked) =>
                                form.setData('require_manager', checked)
                            }
                        />
                    </div>

                    <div className="space-y-3">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <p className="font-medium">
                                    Require Company Signatory
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Select an eligible company user.
                                </p>
                            </div>
                            <Switch
                                checked={form.data.require_company_signatory}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'require_company_signatory',
                                        checked,
                                    )
                                }
                            />
                        </div>
                        {form.data.require_company_signatory ? (
                            <AppSelect
                                value={
                                    form.data.company_signatory_user_id ||
                                    '__none__'
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'company_signatory_user_id',
                                        value === '__none__' ? '' : value,
                                    )
                                }
                            >
                                <AppSelectItem value="__none__">
                                    Select signatory
                                </AppSelectItem>
                                {formOptions.users.map((user) => (
                                    <AppSelectItem
                                        key={user.id}
                                        value={String(user.id)}
                                    >
                                        {user.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        ) : null}
                    </div>

                    <div className="rounded-lg bg-muted/40 p-3 text-sm">
                        <p className="font-medium">Sequence</p>
                        <p className="mt-1 text-muted-foreground">
                            {sequencePreview}
                        </p>
                    </div>

                    {typeof (form.errors as Record<string, string>).steps ===
                    'string' ? (
                        <p className="text-sm text-destructive">
                            {(form.errors as Record<string, string>).steps}
                        </p>
                    ) : null}
                </div>

                <SheetFooter className="mt-8">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button type="button" onClick={submit}>
                        {preset ? 'Save changes' : 'Create preset'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
