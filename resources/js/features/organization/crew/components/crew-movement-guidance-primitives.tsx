import {
    AlertTriangle,
    ArrowRight,
    ChevronDown,
    HelpCircle,
} from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    MovementWorkflowHelp,
    movementWorkflowHelpTopicForAction,
} from '@/features/organization/crew/components/movement-workflow-help';
import type { ReadinessAction } from '@/features/organization/crew/lib/assignment-readiness-guidance';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { cn } from '@/lib/utils';

export function MovementGuidanceHeader({
    subtitle,
}: {
    subtitle?: string;
}): ReactElement {
    return (
        <div>
            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                Movement Guidance
            </p>
            {subtitle ? (
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {subtitle}
                </p>
            ) : null}
        </div>
    );
}

export function GuidanceEmployeeIdentity({
    name,
    employeeNo,
    rankName,
    nationalityName,
    image,
}: {
    name: string;
    employeeNo?: string | null;
    rankName?: string | null;
    nationalityName?: string | null;
    image?: string | null;
}): ReactElement {
    const detailParts = [rankName, employeeNo].filter(Boolean);

    return (
        <div
            data-slot="assignment-readiness-employee"
            className="flex items-start gap-2.5"
        >
            <EmployeeAvatar name={name} image={image} size="md" />
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold">{name}</p>
                {detailParts.length > 0 ? (
                    <p className="text-xs text-muted-foreground">
                        {detailParts.join(' · ')}
                    </p>
                ) : null}
                {nationalityName ? (
                    <p className="text-[11px] text-muted-foreground">
                        {nationalityName}
                    </p>
                ) : null}
            </div>
        </div>
    );
}

export function GuidanceActionButton({
    action,
    href,
    onClick,
    onBeforeNavigate,
}: {
    action: ReadinessAction;
    href?: string | null;
    onClick?: () => void;
    onBeforeNavigate?: () => void;
}): ReactElement | null {
    const helpTopic = movementWorkflowHelpTopicForAction(action.key);

    const actionLabel = (
        <span className="min-w-0 flex-1">
            <span className="flex items-center gap-0.5">
                <span className="block font-semibold">{action.label}</span>
                {helpTopic ? (
                    <MovementWorkflowHelp
                        topic={helpTopic}
                        label={`Explain ${action.label}`}
                    />
                ) : null}
            </span>
            {action.description ? (
                <span className="mt-0.5 block font-normal opacity-80">
                    {action.description}
                </span>
            ) : null}
        </span>
    );

    if (action.kind === 'transfer') {
        return (
            <Button
                type="button"
                variant={action.emphasis === 'primary' ? 'default' : 'outline'}
                size="sm"
                className="h-auto min-h-8 w-full justify-start gap-2 rounded-lg px-2.5 py-1.5 text-left text-xs whitespace-normal"
                onClick={() => {
                    onBeforeNavigate?.();
                    onClick?.();
                }}
            >
                <ArrowRight className="size-3 shrink-0" aria-hidden />
                {actionLabel}
            </Button>
        );
    }

    if (!href) {
        return null;
    }

    return (
        <Button
            type="button"
            variant={action.emphasis === 'primary' ? 'default' : 'outline'}
            size="sm"
            className="h-auto min-h-8 w-full justify-start gap-2 rounded-lg px-2.5 py-1.5 text-left text-xs whitespace-normal"
            onClick={() => {
                onBeforeNavigate?.();
                window.open(href, '_blank', 'noopener');
            }}
        >
            <ArrowRight className="size-3 shrink-0" aria-hidden />
            {actionLabel}
        </Button>
    );
}

export function GuidanceWarning({
    message,
    className,
}: {
    message: string;
    className?: string;
}): ReactElement {
    return (
        <div
            className={cn(
                'flex items-start gap-2 rounded-lg border border-amber-500/35 bg-amber-500/10 px-2.5 py-2 text-xs text-amber-950 dark:text-amber-100',
                className,
            )}
        >
            <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden />
            <p className="leading-relaxed">{message}</p>
        </div>
    );
}

export function GuidanceAdvisory({
    title,
    message,
    className,
}: {
    title: string;
    message: string;
    className?: string;
}): ReactElement {
    return (
        <div
            className={cn(
                'rounded-lg border border-border/60 bg-muted/10 px-2.5 py-2 text-xs',
                className,
            )}
        >
            <p className="font-semibold">{title}</p>
            <p className="mt-0.5 leading-relaxed text-muted-foreground">
                {message}
            </p>
        </div>
    );
}

export function WhyStartBlockedHelp({
    triggerLabel = 'Why is Start Assignment blocked?',
    children,
}: {
    triggerLabel?: string;
    children?: ReactNode;
}): ReactElement {
    return (
        <Collapsible>
            <CollapsibleTrigger className="flex w-full items-center gap-2 rounded-lg border border-border/60 bg-muted/10 px-2.5 py-1.5 text-left text-xs font-medium text-muted-foreground transition-colors hover:bg-muted/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                <HelpCircle className="size-3.5 shrink-0" aria-hidden />
                <span className="flex-1">{triggerLabel}</span>
                <ChevronDown
                    className="size-3.5 shrink-0 transition-transform [[data-state=open]_&]:rotate-180"
                    aria-hidden
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="mt-1.5 rounded-lg border border-border/60 bg-muted/10 px-2.5 py-2 text-xs leading-relaxed text-muted-foreground">
                {children ?? (
                    <>
                        <p>
                            OMS-HRM keeps one active mobilisation cycle per
                            employee. Starting another active assignment could
                            create overlapping or conflicting operational
                            records.
                        </p>
                        <ul className="mt-2 list-disc space-y-0.5 pl-4">
                            <li>Duplicate or ambiguous current vessel</li>
                            <li>Overlapping crew payroll movement days</li>
                            <li>Overlapping sea-service history</li>
                            <li>Incorrect vessel manning</li>
                            <li>Conflicting crew status</li>
                            <li>
                                Inconsistent accommodation or movement history
                            </li>
                        </ul>
                    </>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}
