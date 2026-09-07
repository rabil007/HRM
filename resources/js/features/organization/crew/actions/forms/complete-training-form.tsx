import type { ReactElement } from 'react';
import InputError from '@/components/input-error';
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
import { MovementNextPhaseChoice } from '../movement-next-phase-choice';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function CompleteTrainingForm({
    form,
    config,
    context,
    formOptions,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const isSyncEnabled = Boolean(context.sync_training_enabled);
    const shouldSync =
        isSyncEnabled && Boolean(form.data.sync_training_to_employee_training);

    const selectedCourseId =
        form.data.course_id ??
        formOptions?.courses.find((course) => course.name === form.data.course)
            ?.id ??
        null;

    return (
        <div className="space-y-4">
            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    inputRef={firstFieldRef}
                />
            ) : null}

            {config.nextPhaseOptions && config.nextPhaseLabel ? (
                <MovementNextPhaseChoice
                    id="movement-next-phase"
                    label={config.nextPhaseLabel}
                    value={form.data.next_phase}
                    options={config.nextPhaseOptions}
                    onChange={(value) => form.setData('next_phase', value)}
                    error={form.errors.next_phase}
                />
            ) : null}

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor="movement-training-course">
                        Course{' '}
                        {shouldSync ? (
                            <span className="text-destructive">*</span>
                        ) : (
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        )}
                    </Label>
                    {formOptions ? (
                        <Select
                            value={selectedCourseId?.toString() ?? ''}
                            onValueChange={(value) => {
                                const course = formOptions.courses.find(
                                    (item) => item.id.toString() === value,
                                );
                                form.setData((prev) => ({
                                    ...prev,
                                    course_id: course ? course.id : null,
                                    course: course?.name ?? '',
                                }));
                            }}
                        >
                            <SelectTrigger id="movement-training-course">
                                <SelectValue placeholder="Select course..." />
                            </SelectTrigger>
                            <SelectContent>
                                {formOptions.courses.map((course) => (
                                    <SelectItem
                                        key={course.id}
                                        value={course.id.toString()}
                                    >
                                        {course.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    ) : (
                        <Input
                            id="movement-training-course"
                            value={form.data.course}
                            onChange={(event) =>
                                form.setData('course', event.target.value)
                            }
                        />
                    )}
                    <InputError
                        message={form.errors.course_id || form.errors.course}
                    />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="movement-training-provider">
                        Training center / Provider{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </Label>
                    <Input
                        id="movement-training-provider"
                        value={form.data.provider}
                        placeholder={
                            context.training_provider ??
                            'e.g. Abu Dhabi Maritime Academy'
                        }
                        onChange={(event) =>
                            form.setData('provider', event.target.value)
                        }
                    />
                    <InputError message={form.errors.provider} />
                </div>
            </div>

            {isSyncEnabled ? (
                <div className="rounded-xl border border-primary/20 bg-primary/[0.03] p-4 dark:border-primary/30 dark:bg-primary/[0.05]">
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="movement-sync-training"
                            checked={Boolean(
                                form.data.sync_training_to_employee_training,
                            )}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'sync_training_to_employee_training',
                                    checked === true,
                                )
                            }
                            className="mt-0.5"
                        />
                        <div className="space-y-1 select-none">
                            <Label
                                htmlFor="movement-sync-training"
                                className="cursor-pointer text-sm font-semibold text-foreground"
                            >
                                Add to Employee Training
                            </Label>
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                Sync this completed course to the
                                employee&apos;s formal Training record with
                                issue date matching the completion date.
                            </p>
                        </div>
                    </div>
                    {form.errors.sync_training_to_employee_training ? (
                        <InputError
                            message={
                                form.errors.sync_training_to_employee_training
                            }
                            className="mt-2"
                        />
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
