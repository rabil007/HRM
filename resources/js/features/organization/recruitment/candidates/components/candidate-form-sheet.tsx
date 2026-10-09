import type { InertiaFormProps } from '@inertiajs/react';
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
import { Textarea } from '@/components/ui/textarea';
import type {
    CandidateFormData,
    CandidateFormOptions,
    CandidateIndexRow,
} from '../types';

export function CandidateFormSheet({
    open,
    onOpenChange,
    form,
    options,
    editing,
    lockedRequirementId,
    lockedLineId,
    onSubmit,
    duplicateMessage,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    form: InertiaFormProps<CandidateFormData>;
    options: CandidateFormOptions;
    editing: CandidateIndexRow | null;
    lockedRequirementId?: number | null;
    lockedLineId?: number | null;
    onSubmit: (forceDuplicate?: boolean) => void;
    duplicateMessage?: string | null;
}) {
    const isEdit = editing !== null;
    const selectedRequirement = options.requirements.find(
        (requirement) =>
            String(requirement.id) === form.data.recruitment_requirement_id,
    );
    const lines = selectedRequirement?.lines ?? [];
    const requirementLocked = lockedRequirementId != null;
    const lineLocked = lockedLineId != null;
    const formErrors = form.errors as Record<string, string>;
    const generalFormError = formErrors.candidate || formErrors.lock_version;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border/60 p-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {isEdit ? 'Edit Candidate' : 'Add Candidate'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-xs text-muted-foreground/80">
                        {isEdit
                            ? 'Update profile details. Stage and requirement links are unchanged here.'
                            : 'Create a candidate on an Open requirement and Open position line.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-5 overflow-y-auto p-6">
                    {!isEdit ? (
                        <>
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                    Requirement
                                </Label>
                                <AppSelect
                                    value={
                                        form.data.recruitment_requirement_id ||
                                        'none'
                                    }
                                    onValueChange={(value) => {
                                        form.setData(
                                            'recruitment_requirement_id',
                                            value === 'none' ? '' : value,
                                        );
                                        form.setData(
                                            'recruitment_requirement_line_id',
                                            '',
                                        );
                                    }}
                                    placeholder="Select requirement"
                                    disabled={requirementLocked}
                                >
                                    <AppSelectItem value="none">
                                        Select requirement…
                                    </AppSelectItem>
                                    {options.requirements.map((requirement) => (
                                        <AppSelectItem
                                            key={requirement.id}
                                            value={String(requirement.id)}
                                        >
                                            {requirement.requirement_number}
                                            {requirement.client_name
                                                ? ` — ${requirement.client_name}`
                                                : ''}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {form.errors.recruitment_requirement_id ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.recruitment_requirement_id}
                                    </p>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                    Position
                                </Label>
                                <AppSelect
                                    value={
                                        form.data
                                            .recruitment_requirement_line_id ||
                                        'none'
                                    }
                                    onValueChange={(value) =>
                                        form.setData(
                                            'recruitment_requirement_line_id',
                                            value === 'none' ? '' : value,
                                        )
                                    }
                                    placeholder="Select position line"
                                    disabled={
                                        lineLocked || !selectedRequirement
                                    }
                                >
                                    <AppSelectItem value="none">
                                        Select position…
                                    </AppSelectItem>
                                    {lines.map((line) => (
                                        <AppSelectItem
                                            key={line.id}
                                            value={String(line.id)}
                                        >
                                            {line.position_title}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {form.errors.recruitment_requirement_line_id ? (
                                    <p className="text-xs text-destructive">
                                        {
                                            form.errors
                                                .recruitment_requirement_line_id
                                        }
                                    </p>
                                ) : null}
                            </div>
                        </>
                    ) : null}

                    <div className="space-y-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                            Name
                        </Label>
                        <Input
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            className="h-11 rounded-xl"
                        />
                        {form.errors.name ? (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        ) : null}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Email
                            </Label>
                            <Input
                                type="email"
                                value={form.data.email}
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                                className="h-11 rounded-xl"
                            />
                            {form.errors.email ? (
                                <p className="text-xs text-destructive">
                                    {form.errors.email}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-2">
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Phone
                            </Label>
                            <Input
                                value={form.data.phone}
                                onChange={(event) =>
                                    form.setData('phone', event.target.value)
                                }
                                className="h-11 rounded-xl"
                            />
                            {form.errors.phone ? (
                                <p className="text-xs text-destructive">
                                    {form.errors.phone}
                                </p>
                            ) : null}
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Nationality
                            </Label>
                            <AppSelect
                                value={form.data.nationality_id || 'none'}
                                onValueChange={(value) =>
                                    form.setData(
                                        'nationality_id',
                                        value === 'none' ? '' : value,
                                    )
                                }
                                placeholder="Optional"
                            >
                                <AppSelectItem value="none">
                                    Optional
                                </AppSelectItem>
                                {options.nationalities.map((nationality) => (
                                    <AppSelectItem
                                        key={nationality.id}
                                        value={String(nationality.id)}
                                    >
                                        {nationality.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>
                        <div className="space-y-2">
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Source
                            </Label>
                            <AppSelect
                                value={form.data.source || 'none'}
                                onValueChange={(value) =>
                                    form.setData(
                                        'source',
                                        value === 'none' ? '' : value,
                                    )
                                }
                                placeholder="Optional"
                            >
                                <AppSelectItem value="none">
                                    Optional
                                </AppSelectItem>
                                {options.sources.map((source) => (
                                    <AppSelectItem
                                        key={source.value}
                                        value={source.value}
                                    >
                                        {source.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                            Notes
                        </Label>
                        <Textarea
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                            className="min-h-24 rounded-xl"
                        />
                    </div>

                    <div className="space-y-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                            CV
                        </Label>
                        <Input
                            type="file"
                            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                            onChange={(event) =>
                                form.setData(
                                    'cv',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                        />
                        {form.errors.cv ? (
                            <p className="text-xs text-destructive">
                                {form.errors.cv}
                            </p>
                        ) : null}
                        {isEdit && editing.has_cv ? (
                            <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                <input
                                    type="checkbox"
                                    checked={form.data.remove_cv}
                                    onChange={(event) =>
                                        form.setData(
                                            'remove_cv',
                                            event.target.checked,
                                        )
                                    }
                                />
                                Remove existing CV
                            </label>
                        ) : null}
                    </div>

                    {generalFormError ? (
                        <p className="text-sm text-destructive">
                            {generalFormError}
                        </p>
                    ) : null}

                    {duplicateMessage ||
                    (form.errors as Record<string, string>)
                        .duplicate_warning ? (
                        <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-900 dark:text-amber-100">
                            {duplicateMessage ||
                                (form.errors as Record<string, string>)
                                    .duplicate_warning}
                            <div className="mt-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onSubmit(true)}
                                    disabled={form.processing}
                                >
                                    Save anyway
                                </Button>
                            </div>
                        </div>
                    ) : null}
                </div>

                <SheetFooter className="border-t border-border/60 p-6">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={() => onSubmit(false)}
                        disabled={form.processing}
                    >
                        {isEdit ? 'Save changes' : 'Add candidate'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
