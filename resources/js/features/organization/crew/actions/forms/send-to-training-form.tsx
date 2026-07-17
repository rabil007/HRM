import type { ReactElement } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function SendToTrainingForm({
    form,
    config,
    formOptions,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const expectedBeforeStart =
        form.data.planned_end_at &&
        form.data.occurred_at &&
        form.data.planned_end_at < form.data.occurred_at;

    const selectedCourseId =
        formOptions?.courses.find((course) => course.name === form.data.course)
            ?.id ?? null;

    return (
        <div className="space-y-4">
            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    inputRef={firstFieldRef}
                />
            ) : null}

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor="movement-provider">
                        Training provider (optional)
                    </Label>
                    <Input
                        id="movement-provider"
                        value={form.data.provider}
                        onChange={(event) =>
                            form.setData('provider', event.target.value)
                        }
                    />
                    <InputError message={form.errors.provider} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="movement-course">Course (optional)</Label>
                    {formOptions ? (
                        <Select
                            value={selectedCourseId?.toString() ?? ''}
                            onValueChange={(value) => {
                                const course = formOptions.courses.find(
                                    (item) => item.id.toString() === value,
                                );
                                form.setData('course', course?.name ?? '');
                            }}
                        >
                            <SelectTrigger id="movement-course">
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
                            id="movement-course"
                            value={form.data.course}
                            onChange={(event) =>
                                form.setData('course', event.target.value)
                            }
                        />
                    )}
                    <InputError message={form.errors.course} />
                </div>
            </div>

            <div className="space-y-2">
                <Label htmlFor="movement-planned-end">
                    Expected completion (optional)
                </Label>
                <Input
                    id="movement-planned-end"
                    type="datetime-local"
                    value={form.data.planned_end_at}
                    min={form.data.occurred_at || undefined}
                    onChange={(event) =>
                        form.setData('planned_end_at', event.target.value)
                    }
                />
                {expectedBeforeStart ? (
                    <p className="text-sm text-destructive">
                        Expected completion cannot be before training started.
                    </p>
                ) : null}
                <InputError message={form.errors.planned_end_at} />
            </div>

            <div className="space-y-2">
                <Label htmlFor="movement-training-remarks">
                    Remarks (optional)
                </Label>
                <Textarea
                    id="movement-training-remarks"
                    value={form.data.remarks}
                    onChange={(event) =>
                        form.setData('remarks', event.target.value)
                    }
                    rows={3}
                />
                <InputError message={form.errors.remarks} />
            </div>
        </div>
    );
}
