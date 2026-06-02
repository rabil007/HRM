import { Copy } from 'lucide-react';
import type { ReactElement } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { CreatableSelect } from '@/components/ui/creatable-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CountryOption } from '@/features/organization/employees/types';
import type {
    TrainingDraftFieldErrors,
    TrainingDraftMetadata,
} from '@/features/organization/training/add-training/training-draft';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { useMutableSelectOptions } from '@/hooks/use-mutable-select-options';
import { cn } from '@/lib/utils';
import {
    RecordFormField,
    RequiredIndicator,
    recordFieldInputClass,
    recordFieldLabelClass,
} from '@/pages/organization/_components/record-form-field';
import type { CourseOption } from '@/pages/organization/employee-page.types';

const CERTIFICATE_TEMPLATE_FIELD = 'certificate_path';

export function AddTrainingDraftForm({
    draft,
    courses,
    countries,
    onChange,
    fieldErrors = {},
    onApplyToAll,
    showApplyToAll,
    showField = () => true,
    isFieldRequired = () => false,
    isMissingRequired = () => false,
    existingCertificateUrl = null,
    removeCertificate = false,
    onRemoveCertificateChange,
    certificateError,
}: {
    draft: TrainingDraftMetadata;
    courses: CourseOption[];
    countries: CountryOption[];
    onChange: (patch: Partial<TrainingDraftMetadata>) => void;
    fieldErrors?: TrainingDraftFieldErrors;
    onApplyToAll?: () => void;
    showApplyToAll: boolean;
    showField?: (fieldKey: string) => boolean;
    isFieldRequired?: (fieldKey: string) => boolean;
    isMissingRequired?: (fieldKey: string) => boolean;
    existingCertificateUrl?: string | null;
    removeCertificate?: boolean;
    onRemoveCertificateChange?: (remove: boolean) => void;
    certificateError?: string;
}): ReactElement {
    const { selectOptions: courseSelectOptions, appendOption: appendCourseOption } =
        useMutableSelectOptions(courses);
    const { canCreate: canCreateCourse, createConfig: courseCreateConfig } =
        useCreatableMasterData('course');

    const showCourseSection =
        showField('course_id') ||
        showField('issue_date') ||
        showField('expiry_date') ||
        showField('institute_center') ||
        showField('country_id');

    return (
        <div className="space-y-4">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <div className="text-sm font-semibold">Training information</div>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Enter course completion details for this certificate.
                    </p>
                </div>
                {showApplyToAll && onApplyToAll ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 shrink-0 gap-1.5 text-xs"
                        onClick={onApplyToAll}
                    >
                        <Copy className="h-3.5 w-3.5" />
                        Apply to all
                    </Button>
                ) : null}
            </div>

            {!showCourseSection ? (
                <p className="text-sm text-muted-foreground">
                    No training fields are visible for this profile template.
                </p>
            ) : (
                <div className="space-y-3">
                    {showField('course_id') ? (
                        <RecordFormField
                            field="course_id"
                            highlightMissing={isMissingRequired('course_id')}
                        >
                            <div className="space-y-1.5">
                                <Label
                                    className={recordFieldLabelClass(
                                        isMissingRequired('course_id'),
                                    )}
                                >
                                    Course
                                    <RequiredIndicator show={isFieldRequired('course_id')} />
                                </Label>
                                <CreatableSelect
                                    value={draft.course_id}
                                    onValueChange={(value) => onChange({ course_id: value })}
                                    variant="card"
                                    placeholder="Select course…"
                                    options={courseSelectOptions}
                                    onOptionsChange={(next) => {
                                        const added = next.find(
                                            (option) =>
                                                !courseSelectOptions.some(
                                                    (existing) =>
                                                        existing.value === option.value,
                                                ),
                                        );

                                        if (added) {
                                            appendCourseOption({
                                                id: added.id,
                                                label: added.label,
                                            });
                                        }
                                    }}
                                    creatable
                                    canCreate={canCreateCourse}
                                    createConfig={courseCreateConfig}
                                />
                                {fieldErrors.course_id ? (
                                    <p className="text-xs text-destructive">
                                        {fieldErrors.course_id}
                                    </p>
                                ) : null}
                            </div>
                        </RecordFormField>
                    ) : null}

                    {showField('issue_date') || showField('expiry_date') ? (
                        <div className="grid grid-cols-2 gap-3">
                            {showField('issue_date') ? (
                                <RecordFormField
                                    field="issue_date"
                                    highlightMissing={isMissingRequired('issue_date')}
                                >
                                    <div className="space-y-1.5">
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('issue_date'),
                                            )}
                                        >
                                            Issue date
                                            <RequiredIndicator
                                                show={isFieldRequired('issue_date')}
                                            />
                                        </Label>
                                        <Input
                                            type="date"
                                            className={cn(
                                                recordFieldInputClass(
                                                    isMissingRequired('issue_date'),
                                                ),
                                                'h-10 text-sm',
                                            )}
                                            value={draft.issue_date}
                                            onChange={(event) =>
                                                onChange({ issue_date: event.target.value })
                                            }
                                        />
                                        {fieldErrors.issue_date ? (
                                            <p className="text-xs text-destructive">
                                                {fieldErrors.issue_date}
                                            </p>
                                        ) : null}
                                    </div>
                                </RecordFormField>
                            ) : null}
                            {showField('expiry_date') ? (
                                <RecordFormField
                                    field="expiry_date"
                                    highlightMissing={isMissingRequired('expiry_date')}
                                >
                                    <div className="space-y-1.5">
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('expiry_date'),
                                            )}
                                        >
                                            Expiry date
                                            <RequiredIndicator
                                                show={isFieldRequired('expiry_date')}
                                            />
                                        </Label>
                                        <Input
                                            type="date"
                                            className={cn(
                                                recordFieldInputClass(
                                                    isMissingRequired('expiry_date'),
                                                ),
                                                'h-10 text-sm',
                                            )}
                                            value={draft.expiry_date}
                                            onChange={(event) =>
                                                onChange({ expiry_date: event.target.value })
                                            }
                                        />
                                        {fieldErrors.expiry_date ? (
                                            <p className="text-xs text-destructive">
                                                {fieldErrors.expiry_date}
                                            </p>
                                        ) : null}
                                    </div>
                                </RecordFormField>
                            ) : null}
                        </div>
                    ) : null}

                    {showField('institute_center') ? (
                        <RecordFormField
                            field="institute_center"
                            highlightMissing={isMissingRequired('institute_center')}
                        >
                            <div className="space-y-1.5">
                                <Label
                                    className={recordFieldLabelClass(
                                        isMissingRequired('institute_center'),
                                    )}
                                >
                                    Institute/Center
                                    <RequiredIndicator
                                        show={isFieldRequired('institute_center')}
                                    />
                                </Label>
                                <Input
                                    className={cn(
                                        recordFieldInputClass(
                                            isMissingRequired('institute_center'),
                                        ),
                                        'h-10 text-sm',
                                    )}
                                    placeholder="e.g. BINA SENA MTC"
                                    value={draft.institute_center}
                                    onChange={(event) =>
                                        onChange({ institute_center: event.target.value })
                                    }
                                />
                                {fieldErrors.institute_center ? (
                                    <p className="text-xs text-destructive">
                                        {fieldErrors.institute_center}
                                    </p>
                                ) : null}
                            </div>
                        </RecordFormField>
                    ) : null}

                    {showField('country_id') ? (
                        <RecordFormField
                            field="country_id"
                            highlightMissing={isMissingRequired('country_id')}
                        >
                            <div className="space-y-1.5">
                                <Label
                                    className={recordFieldLabelClass(
                                        isMissingRequired('country_id'),
                                    )}
                                >
                                    Country
                                    <RequiredIndicator show={isFieldRequired('country_id')} />
                                </Label>
                                <AppSelect
                                    value={draft.country_id}
                                    onValueChange={(value) => onChange({ country_id: value })}
                                    variant="card"
                                    placeholder="Select country…"
                                >
                                    <AppSelectItem value="">Select country…</AppSelectItem>
                                    {countries.map((country) => (
                                        <AppSelectItem key={country.id} value={String(country.id)}>
                                            {country.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {fieldErrors.country_id ? (
                                    <p className="text-xs text-destructive">
                                        {fieldErrors.country_id}
                                    </p>
                                ) : null}
                            </div>
                        </RecordFormField>
                    ) : null}

                    {existingCertificateUrl && onRemoveCertificateChange ? (
                        <div className="space-y-2 rounded-xl border border-border/60 bg-muted/20 p-3">
                            <p className="text-xs text-muted-foreground">
                                <a
                                    href={existingCertificateUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="font-medium text-primary hover:underline"
                                >
                                    View current certificate
                                </a>
                                {!removeCertificate
                                    ? ' — upload a new file on the left to replace it.'
                                    : ' — will be removed when you save.'}
                            </p>
                            <label className="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground">
                                <Checkbox
                                    checked={removeCertificate}
                                    onCheckedChange={(checked) =>
                                        onRemoveCertificateChange(checked === true)
                                    }
                                />
                                Remove current certificate
                            </label>
                        </div>
                    ) : null}

                    {certificateError ? (
                        <p className="text-xs text-destructive">{certificateError}</p>
                    ) : null}
                </div>
            )}
        </div>
    );
}

export { CERTIFICATE_TEMPLATE_FIELD };
