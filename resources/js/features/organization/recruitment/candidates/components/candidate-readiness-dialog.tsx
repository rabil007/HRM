import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    joiningKnownGeneralError,
    joiningUnrenderedErrors,
} from '../lib/joining-form-errors';
import type { CandidateDetail, CandidateIndexRow } from '../types';
import type { CandidateReadinessFieldsData } from './candidate-readiness-fields';
import { CandidateReadinessFields } from './candidate-readiness-fields';

function readinessDefaults(
    candidate: CandidateIndexRow | CandidateDetail,
): CandidateReadinessFieldsData {
    const detail = candidate as CandidateDetail;
    const joining = detail.joining;

    return {
        expected_joining_date:
            joining?.expected_joining_date ??
            candidate.expected_joining_date ??
            '',
        joining_readiness_status:
            (joining?.readiness_status as 'pending' | 'ready' | null) ??
            (candidate.joining_readiness_status as
                | 'pending'
                | 'ready'
                | null) ??
            'pending',
        joining_readiness_notes:
            joining?.readiness_notes ?? candidate.joining_readiness_notes ?? '',
        joining_blocker_notes:
            joining?.blocker_notes ?? candidate.joining_blocker_notes ?? '',
        reason: '',
        lock_version: candidate.lock_version,
    };
}

export function CandidateReadinessDialog({
    open,
    onOpenChange,
    candidate,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    candidate: CandidateIndexRow | CandidateDetail | null;
}) {
    const form = useForm<CandidateReadinessFieldsData>({
        expected_joining_date: '',
        joining_readiness_status: 'pending',
        joining_readiness_notes: '',
        joining_blocker_notes: '',
        reason: '',
        lock_version: 0,
    });

    useEffect(() => {
        if (open && candidate) {
            form.setData(readinessDefaults(candidate));
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- intentional open/id trigger
    }, [open, candidate?.id, candidate?.lock_version]);

    if (!candidate) {
        return null;
    }

    const errors = form.errors as Record<string, string>;
    const generalError = joiningKnownGeneralError(errors);
    const unrendered = joiningUnrenderedErrors(errors, [
        'expected_joining_date',
        'joining_readiness_status',
        'joining_readiness_notes',
        'joining_blocker_notes',
        'reason',
    ]);

    const submit = () => {
        if (form.processing) {
            return;
        }

        form.clearErrors();
        form.transform((data) => ({
            expected_joining_date: data.expected_joining_date || null,
            joining_readiness_status: data.joining_readiness_status,
            joining_readiness_notes: data.joining_readiness_notes || null,
            joining_blocker_notes: data.joining_blocker_notes || null,
            reason: data.reason || null,
            lock_version: candidate.lock_version,
            expected_stage: candidate.stage,
        }));
        form.post(
            `/organization/recruitment/candidates/${candidate.id}/joining-readiness`,
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => form.transform((data) => data),
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && !form.processing) {
                    onOpenChange(false);
                }
            }}
        >
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Update Joining Readiness</DialogTitle>
                    <DialogDescription>
                        {candidate.name} · {candidate.position_title}. Set
                        operational readiness and expected joining schedule.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3">
                    {generalError ? (
                        <p className="text-sm text-destructive" role="alert">
                            {generalError}
                        </p>
                    ) : null}
                    {unrendered.map((message) => (
                        <p
                            key={message}
                            className="text-sm text-destructive"
                            role="alert"
                        >
                            {message}
                        </p>
                    ))}

                    <CandidateReadinessFields
                        form={form}
                        idPrefix={`readiness-dialog-${candidate.id}`}
                    />

                    <InputError message={form.errors.reason} />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={form.processing}
                        onClick={submit}
                    >
                        {form.processing ? 'Saving…' : 'Save Readiness'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
