import { Link, useForm } from '@inertiajs/react';
import { Plus, Users } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { CandidateFormSheet } from '@/features/organization/recruitment/candidates/components/candidate-form-sheet';
import {
    CandidateOutcomeBadge,
    CandidateStageBadge,
} from '@/features/organization/recruitment/candidates/components/candidate-stage-badge';
import { emptyCandidateForm } from '@/features/organization/recruitment/candidates/lib/candidate-form';
import type {
    CandidateFormData,
    CandidateFormOptions,
    CandidatePagePermissions,
    CandidateRequirementSummary,
} from '@/features/organization/recruitment/candidates/types';

export function RequirementCandidatesCard({
    requirementId,
    requirementNumber,
    defaultLineId,
    summary,
    options,
    can,
}: {
    requirementId: number;
    requirementNumber: string;
    defaultLineId?: number | null;
    summary: CandidateRequirementSummary;
    options: CandidateFormOptions;
    can: CandidatePagePermissions;
}) {
    const [open, setOpen] = useState(false);
    const [duplicateMessage, setDuplicateMessage] = useState<string | null>(
        null,
    );
    const form = useForm<CandidateFormData>(
        emptyCandidateForm({
            recruitment_requirement_id: String(requirementId),
            recruitment_requirement_line_id: defaultLineId
                ? String(defaultLineId)
                : '',
        }),
    );

    if (!can.view) {
        return null;
    }

    const openCreate = () => {
        form.setData(
            emptyCandidateForm({
                recruitment_requirement_id: String(requirementId),
                recruitment_requirement_line_id: defaultLineId
                    ? String(defaultLineId)
                    : '',
            }),
        );
        form.clearErrors();
        setDuplicateMessage(null);
        setOpen(true);
    };

    const submit = (forceDuplicate = false) => {
        form.transform((data) => ({
            ...data,
            ignore_duplicate_warning: forceDuplicate,
            nationality_id: data.nationality_id || null,
            source: data.source || null,
        }));
        form.post('/organization/recruitment/candidates', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setDuplicateMessage(null);
                form.transform((data) => data);
            },
            onError: (errors) => {
                if (errors.duplicate_warning) {
                    setDuplicateMessage(errors.duplicate_warning);
                }
            },
            onFinish: () => form.transform((data) => data),
        });
    };

    return (
        <section className="rounded-xl border border-border/60 p-5">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <Users className="h-4 w-4 text-muted-foreground" />
                    <h2 className="text-sm font-semibold tracking-wide uppercase">
                        Candidates
                    </h2>
                    <span className="text-sm text-muted-foreground">
                        {summary.total} total
                        {summary.selected > 0
                            ? ` · ${summary.selected} selected`
                            : ''}
                    </span>
                </div>
                <div className="flex gap-2">
                    {can.create ? (
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="mr-1 h-4 w-4" />
                            Add Candidate
                        </Button>
                    ) : null}
                    <Button size="sm" variant="outline" asChild>
                        <Link
                            href={`/organization/recruitment/candidates?requirement_id=${requirementId}`}
                        >
                            View all
                        </Link>
                    </Button>
                </div>
            </div>

            <div className="mb-3 flex flex-wrap gap-2 text-xs text-muted-foreground">
                {Object.entries(summary.by_stage).map(([stage, count]) => (
                    <span key={stage}>
                        {stage}: {count}
                    </span>
                ))}
            </div>

            {summary.recent.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No candidates linked to {requirementNumber} yet.
                </p>
            ) : (
                <ul className="space-y-2">
                    {summary.recent.map((candidate) => (
                        <li
                            key={candidate.id}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border/40 px-3 py-2 text-sm"
                        >
                            <Link
                                href={`/organization/recruitment/candidates/${candidate.id}`}
                                className="font-medium hover:underline"
                            >
                                {candidate.name}
                            </Link>
                            <div className="flex items-center gap-2">
                                <CandidateStageBadge
                                    stage={candidate.stage}
                                    label={candidate.stage_label}
                                />
                                <CandidateOutcomeBadge
                                    outcome={candidate.interview_outcome}
                                    label={candidate.interview_outcome_label}
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <CandidateFormSheet
                open={open}
                onOpenChange={setOpen}
                form={form}
                options={options}
                editing={null}
                lockedRequirementId={requirementId}
                lockedLineId={defaultLineId}
                onSubmit={submit}
                duplicateMessage={duplicateMessage}
            />
        </section>
    );
}
