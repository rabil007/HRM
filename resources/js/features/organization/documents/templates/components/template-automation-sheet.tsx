import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import { update as updateAutomation } from '@/routes/organization/documents/templates/automation';
import type { AutomationPresetOption, CustomTemplate } from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    template: CustomTemplate | null;
    workflowPresets: AutomationPresetOption[];
    signingPresets: AutomationPresetOption[];
};

export function TemplateAutomationSheet({
    open,
    onOpenChange,
    template,
    workflowPresets,
    signingPresets,
}: Props) {
    const version =
        template?.draft_version ?? template?.published_version ?? null;
    const isPdf = template?.template_format === 'pdf_overlay';

    const form = useForm<{
        document_workflow_preset_id: number | null;
        document_signing_preset_id: number | null;
    }>({
        document_workflow_preset_id: null,
        document_signing_preset_id: null,
    });

    useEffect(() => {
        if (!open || !version) {
            return;
        }

        form.setData({
            document_workflow_preset_id:
                version.document_workflow_preset_id ?? null,
            document_signing_preset_id: isPdf
                ? (version.document_signing_preset_id ?? null)
                : null,
        });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when sheet opens for a template version
    }, [open, version?.id, isPdf]);

    function submit() {
        if (!template) {
            return;
        }

        form.put(updateAutomation.url(template.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>After generation</SheetTitle>
                    <SheetDescription>
                        Choose what should happen after this document is
                        generated.
                    </SheetDescription>
                </SheetHeader>

                <div className="mt-6 space-y-4">
                    <div className="space-y-2">
                        <Label>Review & approval</Label>
                        <p className="text-xs text-muted-foreground">
                            Define who reviews or approves generated documents
                            and in what order.
                        </p>
                        <AppSelect
                            value={
                                form.data.document_workflow_preset_id !== null
                                    ? String(
                                          form.data.document_workflow_preset_id,
                                      )
                                    : '__none__'
                            }
                            onValueChange={(value) =>
                                form.setData(
                                    'document_workflow_preset_id',
                                    value === '__none__' ? null : Number(value),
                                )
                            }
                        >
                            <AppSelectItem value="__none__">None</AppSelectItem>
                            {workflowPresets.map((preset) => (
                                <AppSelectItem
                                    key={preset.id}
                                    value={String(preset.id)}
                                >
                                    {preset.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.errors.document_workflow_preset_id ? (
                            <p className="text-sm text-destructive">
                                {form.errors.document_workflow_preset_id}
                            </p>
                        ) : null}
                    </div>

                    {isPdf ? (
                        <div className="space-y-2">
                            <Label>Signing preset</Label>
                            <AppSelect
                                value={
                                    form.data.document_signing_preset_id !==
                                    null
                                        ? String(
                                              form.data
                                                  .document_signing_preset_id,
                                          )
                                        : '__none__'
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'document_signing_preset_id',
                                        value === '__none__'
                                            ? null
                                            : Number(value),
                                    )
                                }
                            >
                                <AppSelectItem value="__none__">
                                    None
                                </AppSelectItem>
                                {signingPresets.map((preset) => (
                                    <AppSelectItem
                                        key={preset.id}
                                        value={String(preset.id)}
                                    >
                                        {preset.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.document_signing_preset_id ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.document_signing_preset_id}
                                </p>
                            ) : null}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Automatic signing is only available for PDF Overlay
                            templates.
                        </p>
                    )}
                </div>

                <SheetFooter className="mt-8">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={!template || form.processing}
                        onClick={submit}
                    >
                        Save automation
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
