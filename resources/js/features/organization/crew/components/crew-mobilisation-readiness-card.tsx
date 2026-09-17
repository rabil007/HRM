import { Link } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    ChevronDown,
    ExternalLink,
    FileText,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent } from '@/components/ui/collapsible';
import { CrewMobilisationReadinessBadge } from '@/features/organization/crew/components/crew-mobilisation-readiness-badge';
import { hasConfiguredMobilisationChecks } from '@/features/organization/crew/lib/mobilisation-readiness';
import type { CrewMobilisationReadiness } from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';

export function CrewMobilisationReadinessCard({
    readiness,
    canViewDocuments,
}: {
    readiness: CrewMobilisationReadiness;
    canViewDocuments: boolean;
}): ReactElement {
    const [open, setOpen] = useState(false);
    const documentsHref =
        canViewDocuments && readiness.documents_href
            ? readiness.documents_href
            : null;
    const hasChecks = hasConfiguredMobilisationChecks(readiness);
    const isReady = readiness.status === 'ready';

    return (
        <Card className="border-border/80 dark:border-white/10">
            <CardHeader className="border-b border-border/50 pb-3 dark:border-white/5">
                <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        {isReady ? (
                            <ShieldCheck className="size-4 text-emerald-600 dark:text-emerald-400" />
                        ) : (
                            <ShieldAlert className="size-4 text-amber-600 dark:text-amber-400" />
                        )}
                        <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                            Mobilisation Checks
                        </CardTitle>
                    </div>
                    <CrewMobilisationReadinessBadge
                        readiness={readiness}
                        compact
                    />
                </div>
            </CardHeader>
            <CardContent className="space-y-3 pt-4">
                <div className="flex items-center justify-between text-xs">
                    <span className="font-medium text-muted-foreground">
                        Compliance status
                    </span>
                    <span className="font-semibold text-foreground">
                        {hasChecks
                            ? `${readiness.checks_clear} of ${readiness.checks_total} clear`
                            : 'No checks configured'}
                    </span>
                </div>

                {readiness.problems.length > 0 ? (
                    <div className="space-y-1.5 rounded-lg border border-red-500/25 bg-red-500/5 p-2.5">
                        <p className="flex items-center gap-1.5 text-xs font-semibold text-red-700 dark:text-red-300">
                            <AlertCircle className="size-3.5 shrink-0" />
                            <span>
                                Action required ({readiness.problems.length})
                            </span>
                        </p>
                        <ul className="space-y-1.5 text-xs text-red-700/90 dark:text-red-300/90">
                            {readiness.problems.map((problem) => (
                                <li
                                    key={`${problem.code}-${problem.document_type_id ?? problem.label}`}
                                    className="flex items-start gap-1.5"
                                >
                                    <span className="mt-1 size-1.5 shrink-0 rounded-full bg-red-500" />
                                    <span className="font-medium text-foreground">
                                        {problem.label}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {readiness.advisory_note ? (
                    <p className="text-[11px] leading-relaxed text-muted-foreground/80 italic">
                        {readiness.advisory_note}
                    </p>
                ) : null}

                <div className="flex items-center justify-between gap-2 pt-1">
                    {documentsHref ? (
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            className="h-7 text-xs"
                        >
                            <Link
                                href={documentsHref}
                                className="flex items-center gap-1.5"
                            >
                                <FileText className="size-3.5" />
                                <span>Open Documents</span>
                                <ExternalLink className="size-3 opacity-60" />
                            </Link>
                        </Button>
                    ) : (
                        <span />
                    )}

                    {(readiness.checks?.length ?? 0) > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
                            onClick={() => setOpen(!open)}
                        >
                            {open
                                ? 'Hide checks'
                                : `All checks (${readiness.checks?.length})`}
                            <ChevronDown
                                className={cn(
                                    'ml-1 size-3 transition-transform duration-200',
                                    open && 'rotate-180',
                                )}
                            />
                        </Button>
                    ) : null}
                </div>

                {(readiness.checks?.length ?? 0) > 0 ? (
                    <Collapsible open={open} onOpenChange={setOpen}>
                        <CollapsibleContent className="pt-1">
                            <ul className="max-h-44 divide-y divide-border/20 overflow-y-auto rounded-md border border-border/40 bg-background/50 p-2 text-xs text-muted-foreground">
                                {readiness.checks?.map((check) => (
                                    <li
                                        key={`${check.code}-${check.document_type_id ?? check.label}`}
                                        className="flex items-center justify-between gap-2 py-1 first:pt-0 last:pb-0"
                                    >
                                        <span className="truncate">
                                            {check.label}
                                        </span>
                                        {check.severity === 'ok' ? (
                                            <CheckCircle2 className="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                        ) : (
                                            <AlertCircle className="size-3.5 shrink-0 text-red-500" />
                                        )}
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
