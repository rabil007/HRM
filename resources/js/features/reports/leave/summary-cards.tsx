import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { LeaveReportProps } from './types';

export function LeaveReportSummaryCards({
    summary,
    selectedLeaveTypeId,
    onSelectLeaveType,
}: {
    summary: LeaveReportProps['summary'];
    selectedLeaveTypeId: string;
    onSelectLeaveType: (leaveTypeId: string) => void;
}) {
    return (
        <div
            className="grid grid-cols-[repeat(auto-fit,minmax(10.5rem,1fr))] gap-3"
            role="group"
            aria-label="Filter by leave type"
        >
            <FilterCard
                label="All leave types"
                value={summary.total_requests}
                hint="Requests in this view"
                active={selectedLeaveTypeId === ''}
                onClick={() => onSelectLeaveType('')}
            />

            {summary.leave_types.map((leaveType) => {
                const id = String(leaveType.id);
                const isActive = selectedLeaveTypeId === id;

                return (
                    <FilterCard
                        key={leaveType.id}
                        label={leaveType.name}
                        value={leaveType.request_count}
                        hint={leaveType.code || 'Leave type'}
                        hintMono={Boolean(leaveType.code)}
                        color={leaveType.color}
                        active={isActive}
                        onClick={() => onSelectLeaveType(isActive ? '' : id)}
                        ariaLabel={
                            isActive
                                ? `Clear ${leaveType.name} filter and show all leave types`
                                : `Filter by ${leaveType.name}`
                        }
                    />
                );
            })}
        </div>
    );
}

function FilterCard({
    label,
    value,
    hint,
    hintMono = false,
    color,
    active,
    onClick,
    ariaLabel,
}: {
    label: string;
    value: number;
    hint: string;
    hintMono?: boolean;
    color?: string | null;
    active: boolean;
    onClick: () => void;
    ariaLabel?: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            aria-label={ariaLabel}
            className="h-full w-full rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <Card
                className={cn(
                    'h-full transition-colors',
                    active && 'border-primary/40 bg-primary/5',
                )}
            >
                <CardContent className="p-3">
                    <div className="flex items-start gap-2">
                        {color ? (
                            <span
                                className="mt-1 size-2.5 shrink-0 rounded-full"
                                style={{ backgroundColor: color }}
                                aria-hidden
                            />
                        ) : null}
                        <p className="line-clamp-2 min-w-0 text-sm font-semibold tracking-tight">
                            {label}
                        </p>
                    </div>
                    <p className="mt-1 text-xl font-bold tabular-nums">
                        {value.toLocaleString()}
                    </p>
                    <p
                        className={cn(
                            'mt-1 text-[11px] text-muted-foreground',
                            hintMono && 'font-mono',
                        )}
                    >
                        {hint}
                    </p>
                </CardContent>
            </Card>
        </button>
    );
}
