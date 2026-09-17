import type { ReactElement } from 'react';
import type {
    LastOperationalChangeInput,
    OperationalStatePermissions,
} from '@/features/organization/crew/lib/crew-operational-state';
import {
    currentStateLabel,
    formatOtherValidActionLabel,
    lastOperationalChangeSummary,
    nextNormalActionLabel,
    otherValidMovementActions,
} from '@/features/organization/crew/lib/crew-operational-state';
import type { CrewRecommendedAction } from '@/features/organization/crew/types';

export function CrewOperationalStatePanel({
    assignment,
    recommended,
    permissions,
    showLastChange = false,
    className,
}: {
    assignment: LastOperationalChangeInput & {
        current_phase: {
            code: string;
            label: string;
            status?: string;
            started_at?: string | null;
        } | null;
        status: string;
        status_label: string;
        available_actions?: string[];
    };
    recommended?: CrewRecommendedAction | null;
    permissions: OperationalStatePermissions;
    showLastChange?: boolean;
    className?: string;
}): ReactElement {
    const otherActions = otherValidMovementActions(
        assignment.available_actions,
        recommended,
        permissions,
    );
    const nextAction = nextNormalActionLabel(recommended);
    const lastChange = showLastChange
        ? lastOperationalChangeSummary(assignment)
        : null;

    return (
        <section className={className} aria-label="Current operational state">
            <dl className="space-y-3 text-sm">
                <div>
                    <dt className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                        Current state
                    </dt>
                    <dd className="mt-1 font-medium text-foreground">
                        {currentStateLabel(assignment)}
                    </dd>
                </div>
                {nextAction ? (
                    <div>
                        <dt className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Next normal action
                        </dt>
                        <dd className="mt-1 font-medium text-foreground">
                            {nextAction}
                        </dd>
                        {recommended?.reason ? (
                            <dd className="mt-1 text-xs text-muted-foreground">
                                {recommended.reason}
                            </dd>
                        ) : null}
                    </div>
                ) : null}
                {otherActions.length > 0 ? (
                    <div>
                        <dt className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Other valid actions
                        </dt>
                        <dd className="mt-1 text-foreground">
                            {otherActions
                                .map((action) =>
                                    formatOtherValidActionLabel(action),
                                )
                                .join(' · ')}
                        </dd>
                    </div>
                ) : null}
                {lastChange ? (
                    <div>
                        <dt className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Last movement
                        </dt>
                        <dd className="mt-1 text-foreground">
                            {lastChange.label}
                            <span className="text-muted-foreground">
                                {' '}
                                · {lastChange.occurredAt}
                            </span>
                        </dd>
                    </div>
                ) : null}
            </dl>
        </section>
    );
}
