import { useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { SigningPresetSummary } from '@/features/organization/documents/signing/types';
import { store } from '@/routes/organization/documents/employee/files/signing-flows';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    documentId: number;
    presets: SigningPresetSummary[];
};

export function StartSigningFlowDialog({
    open,
    onOpenChange,
    employeeId,
    documentId,
    presets,
}: Props) {
    const form = useForm({
        document_signing_preset_id: '',
    });

    const selected = useMemo(
        () =>
            presets.find(
                (preset) =>
                    String(preset.id) === form.data.document_signing_preset_id,
            ) ?? null,
        [form.data.document_signing_preset_id, presets],
    );

    function submit() {
        form.post(
            store.url({
                employee: employeeId,
                document: documentId,
            }),
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Start signing flow</DialogTitle>
                    <DialogDescription>
                        Choose a signing preset. Recipients are resolved and
                        snapshotted when the flow starts.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label>Preset</Label>
                        <AppSelect
                            value={
                                form.data.document_signing_preset_id ||
                                '__none__'
                            }
                            onValueChange={(value) =>
                                form.setData(
                                    'document_signing_preset_id',
                                    value === '__none__' ? '' : value,
                                )
                            }
                        >
                            <AppSelectItem value="__none__">
                                Select preset
                            </AppSelectItem>
                            {presets.map((preset) => (
                                <AppSelectItem
                                    key={preset.id}
                                    value={String(preset.id)}
                                >
                                    {preset.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    {selected ? (
                        <div className="rounded-lg border border-border/70 p-3 text-sm">
                            <p className="font-medium">Sequence</p>
                            <p className="mt-1 text-muted-foreground">
                                {selected.routing_summary}
                            </p>
                            {selected.steps.some(
                                (step) => step.recipient_role === 'manager',
                            ) ? (
                                <p className="mt-2 text-muted-foreground">
                                    Department manager will be resolved from the
                                    employee&apos;s current management hierarchy
                                    when the flow starts.
                                </p>
                            ) : null}
                        </div>
                    ) : null}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={
                            form.processing ||
                            !form.data.document_signing_preset_id
                        }
                        onClick={submit}
                    >
                        Start flow
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
