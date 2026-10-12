import type { InertiaFormProps } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
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
import { cn } from '@/lib/utils';
import { RequiredIndicator } from '@/pages/organization/_components/record-form-field';
import type {
    CandidateFormData,
    CandidateFormOptions,
    CandidateIndexRow,
} from '../types';

function fieldInvalidClass(hasError: boolean): string {
    return hasError
        ? 'border-destructive focus-visible:ring-destructive/30'
        : '';
}

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
    const firstErrorRef = useRef<HTMLDivElement | null>(null);
    const errorKeys = Object.keys(form.errors);
    const errorSignature = errorKeys.join('|');

    useEffect(() => {
        if (errorSignature === '' || !open) {
            return;
        }

        firstErrorRef.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
    }, [errorSignature, open]);

    const contactHintId = 'candidate-contact-requirement';

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
                    {errorKeys.length > 0 ? (
                        <div
                            ref={firstErrorRef}
                            className="rounded-xl border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
                            role="alert"
                            aria-live="assertive"
                        >
                            Fix the highlighted fields before saving.
                        </div>
                    ) : null}

                    {!isEdit ? (
                        <>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="candidate_requirement"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Requirement
                                    <RequiredIndicator show />
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
                                    invalid={Boolean(
                                        form.errors.recruitment_requirement_id,
                                    )}
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
                                    <RequiredIndicator show />
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
                                    invalid={Boolean(
                                        form.errors
                                            .recruitment_requirement_line_id,
                                    )}
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
                        <Label
                            htmlFor="candidate_name"
                            className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                        >
                            Name
                            <RequiredIndicator show />
                        </Label>
                        <Input
                            id="candidate_name"
                            value={form.data.name}
                            required
                            aria-required
                            aria-invalid={Boolean(form.errors.name)}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            className={cn(
                                'h-11 rounded-xl',
                                fieldInvalidClass(Boolean(form.errors.name)),
                            )}
                        />
                        {form.errors.name ? (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        ) : null}
                    </div>

                    <fieldset className="space-y-3 rounded-xl border border-border/60 p-3">
                        <legend className="px-1 text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                            Contact information
                        </legend>
                        <p
                            id={contactHintId}
                            className="text-xs text-muted-foreground"
                        >
                            Provide at least one: Email or Phone.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="candidate_email"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Email
                                </Label>
                                <Input
                                    id="candidate_email"
                                    type="email"
                                    value={form.data.email}
                                    aria-describedby={contactHintId}
                                    aria-invalid={Boolean(form.errors.email)}
                                    onChange={(event) =>
                                        form.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                    className={cn(
                                        'h-11 rounded-xl',
                                        fieldInvalidClass(
                                            Boolean(form.errors.email),
                                        ),
                                    )}
                                />
                                {form.errors.email ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.email}
                                    </p>
                                ) : null}
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="candidate_phone"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Phone
                                </Label>
                                <Input
                                    id="candidate_phone"
                                    value={form.data.phone}
                                    aria-describedby={contactHintId}
                                    aria-invalid={Boolean(form.errors.phone)}
                                    onChange={(event) =>
                                        form.setData(
                                            'phone',
                                            event.target.value,
                                        )
                                    }
                                    className={cn(
                                        'h-11 rounded-xl',
                                        fieldInvalidClass(
                                            Boolean(form.errors.phone),
                                        ),
                                    )}
                                />
                                {form.errors.phone ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.phone}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                    </fieldset>

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
                        <Label
                            htmlFor="candidate_notes"
                            className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                        >
                            Notes
                        </Label>
                        <Textarea
                            id="candidate_notes"
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                            className="min-h-24 rounded-xl"
                        />
                    </div>

                    <div className="space-y-2">
                        <Label
                            htmlFor="candidate_cv"
                            className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                        >
                            CV
                        </Label>
                        <Input
                            id="candidate_cv"
                            type="file"
                            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                            aria-invalid={Boolean(form.errors.cv)}
                            onChange={(event) =>
                                form.setData(
                                    'cv',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                            className={fieldInvalidClass(
                                Boolean(form.errors.cv),
                            )}
                        />
                        <p className="text-xs text-muted-foreground">
                            Optional. PDF, Word, or image up to 10 MB.
                        </p>
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
                        <p className="text-sm text-destructive" role="alert">
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
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={() => onSubmit(false)}
                        disabled={form.processing}
                    >
                        {form.processing
                            ? 'Saving…'
                            : isEdit
                              ? 'Save changes'
                              : 'Add candidate'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
