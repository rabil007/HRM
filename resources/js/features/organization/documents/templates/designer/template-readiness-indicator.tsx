import { AlertTriangle, Check } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import {
    readinessDisplayState,
    readinessFixAction,
} from '../lib/template-workflow';
import type { LocalReadinessIssue } from '../lib/template-workflow';
import type { TemplateReadiness, TemplateReadinessIssue } from '../types';

type Issue = TemplateReadinessIssue | LocalReadinessIssue;

type Props = {
    readiness: TemplateReadiness | null;
    issues: Issue[];
    hasUnsavedChanges: boolean;
    configurationBlockingCount: number;
    publishBlocked: boolean;
    canMutate: boolean;
    onFix: (issue: Issue) => void;
};

export function TemplateReadinessIndicator({
    readiness,
    issues,
    hasUnsavedChanges,
    configurationBlockingCount,
    publishBlocked,
    canMutate,
    onFix,
}: Props) {
    const [open, setOpen] = useState(false);
    const display = readinessDisplayState({
        configurationBlockingCount,
        hasUnsavedChanges,
        serverReady: Boolean(readiness?.ready) && !publishBlocked,
    });
    const readyLook = display.kind !== 'issues';

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    aria-label={`Template readiness: ${display.label}${display.detail ? `. ${display.detail}` : ''}`}
                    className={cn(
                        'h-auto min-h-8 gap-1.5 py-1 text-xs',
                        readyLook
                            ? 'border-emerald-300 text-emerald-700 dark:border-emerald-800 dark:text-emerald-400'
                            : 'border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-300',
                    )}
                >
                    {readyLook ? (
                        <Check className="size-3.5" />
                    ) : (
                        <AlertTriangle className="size-3.5" />
                    )}
                    <span className="flex flex-col items-start leading-none">
                        <span>{display.label}</span>
                        {display.detail ? (
                            <span className="text-[10px] font-normal text-muted-foreground">
                                {display.detail}
                            </span>
                        ) : null}
                    </span>
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80 space-y-3 p-3">
                <p className="text-xs font-semibold">Template readiness</p>
                <ReadinessSection
                    title="Design"
                    issues={issues.filter(
                        (issue) => issue.section === 'design',
                    )}
                    canMutate={canMutate}
                    onFix={(issue) => {
                        onFix(issue);
                        setOpen(false);
                    }}
                />
                <ReadinessSection
                    title="Workflow"
                    issues={issues.filter(
                        (issue) => issue.section === 'workflow',
                    )}
                    canMutate={canMutate}
                    onFix={(issue) => {
                        onFix(issue);
                        setOpen(false);
                    }}
                />
                <ReadinessSection
                    title="Signing"
                    issues={issues.filter(
                        (issue) => issue.section === 'signing',
                    )}
                    canMutate={canMutate}
                    onFix={(issue) => {
                        onFix(issue);
                        setOpen(false);
                    }}
                />
                <ReadinessSection
                    title="Version"
                    issues={issues.filter(
                        (issue) => issue.section === 'version',
                    )}
                    canMutate={canMutate}
                    onFix={(issue) => {
                        onFix(issue);
                        setOpen(false);
                    }}
                />
                {configurationBlockingCount > 0 || hasUnsavedChanges ? (
                    <p className="text-[11px] text-muted-foreground">
                        {configurationBlockingCount > 0
                            ? `${configurationBlockingCount} ${configurationBlockingCount === 1 ? 'issue' : 'issues'} must be fixed before publishing.`
                            : 'Save the draft before publishing.'}
                    </p>
                ) : null}
            </PopoverContent>
        </Popover>
    );
}

function ReadinessSection({
    title,
    issues,
    canMutate,
    onFix,
}: {
    title: string;
    issues: Issue[];
    canMutate: boolean;
    onFix: (issue: Issue) => void;
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
                    className="flex items-start justify-between gap-2"
                >
                    <p className="text-[11px] text-foreground">
                        {issue.blocking ? '⚠' : 'ℹ'} {issue.message}
                    </p>
                    {canMutate ? (
                        <FixButton issue={issue} onFix={onFix} />
                    ) : null}
                </div>
            ))}
        </div>
    );
}

function FixButton({
    issue,
    onFix,
}: {
    issue: Issue;
    onFix: (issue: Issue) => void;
}) {
    const action = readinessFixAction(issue);

    if (!action) {
        return null;
    }

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-6 shrink-0 px-2 text-[11px]"
            onClick={() => onFix(issue)}
        >
            {action.label}
        </Button>
    );
}
