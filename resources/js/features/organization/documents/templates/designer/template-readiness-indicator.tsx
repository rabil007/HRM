import { AlertTriangle, Check } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { READINESS_CODES } from '../lib/template-workflow';
import type { LocalReadinessIssue } from '../lib/template-workflow';
import type { TemplateReadiness, TemplateReadinessIssue } from '../types';

type Props = {
    readiness: TemplateReadiness | null;
    localIssues: LocalReadinessIssue[];
    hasUnsavedChanges: boolean;
    blockingCount: number;
    onFix: (issue: TemplateReadinessIssue | LocalReadinessIssue) => void;
};

export function TemplateReadinessIndicator({
    readiness,
    localIssues,
    hasUnsavedChanges,
    blockingCount,
    onFix,
}: Props) {
    const issues = hasUnsavedChanges ? localIssues : (readiness?.issues ?? []);
    const ready = !hasUnsavedChanges && Boolean(readiness?.ready);

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className={cn(
                        'h-8 gap-1.5 text-xs',
                        ready
                            ? 'border-emerald-300 text-emerald-700 dark:border-emerald-800 dark:text-emerald-400'
                            : 'border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-300',
                    )}
                >
                    {ready ? (
                        <>
                            <Check className="size-3.5" />
                            Ready to publish
                        </>
                    ) : (
                        <>
                            <AlertTriangle className="size-3.5" />
                            {blockingCount}{' '}
                            {blockingCount === 1 ? 'issue' : 'issues'}
                        </>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80 space-y-3 p-3">
                <p className="text-xs font-semibold">Template readiness</p>
                <ReadinessSection
                    title="Design"
                    issues={issues.filter(
                        (issue) => issue.section === 'design',
                    )}
                    onFix={onFix}
                />
                <ReadinessSection
                    title="Workflow"
                    issues={issues.filter(
                        (issue) => issue.section === 'workflow',
                    )}
                    onFix={onFix}
                />
                <ReadinessSection
                    title="Signing"
                    issues={issues.filter(
                        (issue) => issue.section === 'signing',
                    )}
                    onFix={onFix}
                />
                <ReadinessSection
                    title="Version"
                    issues={issues.filter(
                        (issue) => issue.section === 'version',
                    )}
                    onFix={onFix}
                />
                {blockingCount > 0 ? (
                    <p className="text-[11px] text-muted-foreground">
                        {blockingCount}{' '}
                        {blockingCount === 1 ? 'issue' : 'issues'} must be fixed
                        before publishing.
                    </p>
                ) : null}
            </PopoverContent>
        </Popover>
    );
}

function ReadinessSection({
    title,
    issues,
    onFix,
}: {
    title: string;
    issues: Array<TemplateReadinessIssue | LocalReadinessIssue>;
    onFix: (issue: TemplateReadinessIssue | LocalReadinessIssue) => void;
}) {
    if (issues.length === 0) {
        return (
            <div className="space-y-1">
                <p className="text-[11px] font-semibold uppercase">{title}</p>
                <p className="text-[11px] text-emerald-700 dark:text-emerald-400">
                    ✓ No issues
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-1.5">
            <p className="text-[11px] font-semibold uppercase">{title}</p>
            {issues.map((issue) => (
                <div
                    key={issue.code + JSON.stringify(issue.meta)}
                    className="space-y-1"
                >
                    <p className="text-[11px] text-foreground">
                        {issue.blocking ? '⚠' : 'ℹ'} {issue.message}
                    </p>
                    <FixButton issue={issue} onFix={onFix} />
                </div>
            ))}
        </div>
    );
}

function FixButton({
    issue,
    onFix,
}: {
    issue: TemplateReadinessIssue | LocalReadinessIssue;
    onFix: (issue: TemplateReadinessIssue | LocalReadinessIssue) => void;
}) {
    const fix = String(issue.meta.fix ?? '');

    if (fix === '') {
        return null;
    }

    const label =
        fix === 'save_draft'
            ? 'Save Draft'
            : fix === 'configure_workflow' || fix === 'configure_signing'
              ? 'Configure Workflow'
              : fix === 'place_on_pdf'
                ? 'Place on PDF'
                : fix === 'remove_signature_placements'
                  ? 'Remove signature placements'
                  : 'Fix';

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-6 px-2 text-[11px]"
            onClick={() => onFix(issue)}
        >
            {label}
        </Button>
    );
}

export function readinessFixCode(
    issue: TemplateReadinessIssue | LocalReadinessIssue,
): string {
    return String(issue.meta.fix ?? issue.code);
}

export { READINESS_CODES };
