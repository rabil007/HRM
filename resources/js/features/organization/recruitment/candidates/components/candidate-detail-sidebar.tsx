import { Link } from '@inertiajs/react';
import { Download, FileWarning } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import type { CandidateDetail } from '../types';

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="text-sm break-words text-foreground">{children}</dd>
        </div>
    );
}

export function CandidateDetailSidebar({
    candidate,
}: {
    candidate: CandidateDetail;
}) {
    return (
        <aside className="space-y-4 lg:sticky lg:top-4 lg:self-start">
            <section className="rounded-xl border border-border/60 bg-card p-4">
                <h2 className="mb-3 text-sm font-semibold tracking-wide uppercase">
                    Candidate
                </h2>
                <dl className="space-y-3">
                    <Row label="Email">
                        {candidate.email ? (
                            <a
                                href={`mailto:${candidate.email}`}
                                className="text-primary hover:underline"
                            >
                                {candidate.email}
                            </a>
                        ) : (
                            '—'
                        )}
                    </Row>
                    <Row label="Phone">
                        {candidate.phone ? (
                            <a
                                href={`tel:${candidate.phone}`}
                                className="text-primary hover:underline"
                            >
                                {candidate.phone}
                            </a>
                        ) : (
                            '—'
                        )}
                    </Row>
                    <Row label="Nationality">
                        {candidate.nationality || '—'}
                    </Row>
                    <Row label="Source">{candidate.source_label || '—'}</Row>
                    <Row label="Notes">
                        <span className="whitespace-pre-wrap">
                            {candidate.notes?.trim() || '—'}
                        </span>
                    </Row>
                </dl>
            </section>

            <section className="rounded-xl border border-border/60 bg-card p-4">
                <h2 className="mb-3 text-sm font-semibold tracking-wide uppercase">
                    Requirement
                </h2>
                <dl className="space-y-3">
                    <Row label="Requirement">
                        {candidate.requirement ? (
                            <Link
                                href={`/organization/recruitment/requirements/${candidate.requirement.id}`}
                                className="font-medium text-primary hover:underline"
                            >
                                {candidate.requirement.requirement_number}
                            </Link>
                        ) : (
                            candidate.requirement_number
                        )}
                    </Row>
                    <Row label="Position">
                        {candidate.line?.position_title ||
                            candidate.position_title}
                    </Row>
                    <Row label="Client">
                        {candidate.requirement?.client_name || '—'}
                    </Row>
                    <Row label="Assigned recruiter">
                        {candidate.requirement?.assigned_to_name || '—'}
                    </Row>
                </dl>
            </section>

            <section className="rounded-xl border border-border/60 bg-card p-4">
                <h2 className="mb-3 text-sm font-semibold tracking-wide uppercase">
                    Documents
                </h2>
                {candidate.has_cv ? (
                    <div className="space-y-2">
                        <p className="text-sm break-all">
                            {candidate.cv_original_file_name || 'CV uploaded'}
                        </p>
                        {candidate.can_download_cv ? (
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={`/organization/recruitment/candidates/${candidate.id}/cv`}
                                >
                                    <Download className="mr-2 h-3.5 w-3.5" />
                                    Download CV
                                </a>
                            </Button>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                CV is on file. You need download permission to
                                open it.
                            </p>
                        )}
                    </div>
                ) : (
                    <div className="flex items-start gap-2 rounded-lg border border-dashed border-amber-500/40 bg-amber-50/60 px-3 py-2.5 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
                        <FileWarning className="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <p className="font-medium">No CV uploaded</p>
                            <p className="text-xs opacity-90">
                                Add a CV if required for this candidate.
                            </p>
                        </div>
                    </div>
                )}
            </section>
        </aside>
    );
}
