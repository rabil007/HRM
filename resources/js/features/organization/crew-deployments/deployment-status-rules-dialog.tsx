import type { ReactElement } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { DeploymentStatusBadge } from '@/features/organization/crew-deployments/deployment-status-badge';
import type { DeploymentStatusRules } from '@/features/organization/crew-deployments/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    rules: DeploymentStatusRules;
};

export function DeploymentStatusRulesDialog({
    open,
    onOpenChange,
    rules,
}: Props): ReactElement {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[min(85vh,720px)] flex-col gap-0 overflow-hidden sm:max-w-2xl">
                <DialogHeader className="shrink-0 border-b border-border/60 pb-4">
                    <DialogTitle>Deployment status rules</DialogTitle>
                    <DialogDescription>{rules.intro}</DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto py-4 pr-1">
                    <section className="rounded-lg border border-border/60 bg-muted/20 px-4 py-3 text-sm text-muted-foreground">
                        {rules.priority_note}
                    </section>

                    <section className="space-y-4">
                        {rules.statuses.map((item, index) => (
                            <article
                                key={item.status}
                                className="rounded-lg border border-border/60 bg-card px-4 py-3 dark:border-white/10 dark:bg-white/[0.02]"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-xs font-bold text-muted-foreground/50 tabular-nums">
                                        {index + 1}.
                                    </span>
                                    <DeploymentStatusBadge
                                        status={item.status}
                                        label={item.label}
                                    />
                                </div>
                                <p className="mt-2 text-sm text-foreground">
                                    {item.summary}
                                </p>
                                <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                                    {item.conditions.map((condition) => (
                                        <li key={condition}>{condition}</li>
                                    ))}
                                </ul>
                                {item.badge ? (
                                    <p className="mt-2 text-xs text-muted-foreground/80">
                                        {item.badge}
                                    </p>
                                ) : null}
                            </article>
                        ))}
                    </section>

                    <section className="rounded-lg border border-teal-500/20 bg-teal-500/5 px-4 py-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <DeploymentStatusBadge
                                status="in_home"
                                label={rules.in_home.title}
                            />
                        </div>
                        <p className="mt-2 text-sm text-foreground">
                            {rules.in_home.summary}
                        </p>
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                            {rules.in_home.conditions.map((condition) => (
                                <li key={condition}>{condition}</li>
                            ))}
                        </ul>
                    </section>

                    <section className="rounded-lg border border-border/60 bg-muted/10 px-4 py-3">
                        <h3 className="text-sm font-semibold text-foreground">
                            {rules.date_highlights.title}
                        </h3>
                        <p className="mt-2 text-sm text-muted-foreground">
                            <span className="font-medium text-red-400">
                                Overdue
                            </span>{' '}
                            — {rules.date_highlights.overdue}
                        </p>
                        <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                            {rules.date_highlights.fields.map((field) => (
                                <li key={field}>{field}</li>
                            ))}
                        </ul>
                    </section>

                    <section className="rounded-lg border border-red-500/20 bg-red-500/5 px-4 py-3">
                        <h3 className="text-sm font-semibold text-foreground">
                            Needs update hints
                        </h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                            When a record shows Needs update, hover the badge to
                            see which date is missing. Common messages:
                        </p>
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                            {rules.needs_update_hints.map((hint) => (
                                <li key={hint}>
                                    <span className="font-mono text-xs text-foreground/80">
                                        {hint}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            </DialogContent>
        </Dialog>
    );
}
