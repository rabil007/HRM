import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { CrewMobilisationReadinessBadge } from '@/features/organization/crew/components/crew-mobilisation-readiness-badge';
import type { CrewMobilisationReadiness } from '@/features/organization/crew/types';

function problemPrefix(severity: string): string {
    return severity === 'blocker' ? '❌' : '⚠';
}

export function CrewMobilisationReadinessCard({
    readiness,
    canViewDocuments,
    canViewTraining,
}: {
    readiness: CrewMobilisationReadiness;
    canViewDocuments: boolean;
    canViewTraining: boolean;
}): ReactElement {
    const [open, setOpen] = useState(false);
    const documentsHref =
        canViewDocuments && readiness.documents_href
            ? readiness.documents_href
            : null;
    const trainingHref =
        canViewTraining && readiness.training_href
            ? readiness.training_href
            : null;
    const extraChecks = (readiness.checks ?? []).filter(
        (check) =>
            !readiness.problems.some(
                (problem) =>
                    problem.code === check.code &&
                    problem.document_type_id === check.document_type_id,
            ),
    );

    return (
        <Card className="border-border/80 dark:border-white/10">
            <CardHeader className="pb-3">
                <CardTitle className="text-base">
                    Mobilisation Readiness
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="flex flex-wrap items-center gap-3">
                    <CrewMobilisationReadinessBadge readiness={readiness} />
                    {readiness.checks_total > 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {readiness.checks_clear} of {readiness.checks_total}{' '}
                            checks clear
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No required document checks configured
                        </p>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">
                    {readiness.advisory_note}
                </p>
                {readiness.problems.length > 0 ? (
                    <ul className="space-y-1 text-sm">
                        {readiness.problems.map((problem) => (
                            <li
                                key={`${problem.code}-${problem.document_type_id ?? problem.label}`}
                            >
                                {problemPrefix(problem.severity)}{' '}
                                {problem.label}
                            </li>
                        ))}
                    </ul>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    {documentsHref ? (
                        <Button asChild variant="outline" size="sm">
                            <Link href={documentsHref}>Open Documents</Link>
                        </Button>
                    ) : null}
                    {trainingHref ? (
                        <Button asChild variant="outline" size="sm">
                            <Link href={trainingHref}>Open Training</Link>
                        </Button>
                    ) : null}
                </div>
                {(readiness.checks?.length ?? 0) > 0 ? (
                    <Collapsible open={open} onOpenChange={setOpen}>
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-8 px-2 text-xs text-muted-foreground"
                            >
                                {open ? 'Hide details' : 'Show all checks'}
                                <ChevronDown
                                    className={`ml-1 size-3.5 transition-transform ${open ? 'rotate-180' : ''}`}
                                />
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="pt-2">
                            <ul className="space-y-1 text-sm text-muted-foreground">
                                {(extraChecks.length > 0
                                    ? extraChecks
                                    : (readiness.checks ?? [])
                                ).map((check) => (
                                    <li
                                        key={`${check.code}-${check.document_type_id ?? check.label}`}
                                    >
                                        {check.severity === 'ok' ? '✓' : ''}{' '}
                                        {check.label}
                                    </li>
                                ))}
                            </ul>
                        </CollapsibleContent>
                    </Collapsible>
                ) : null}
            </CardContent>
        </Card>
    );
}
