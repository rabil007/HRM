import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { BalanceLeaveTypeOption } from './types';

export function LeaveBalanceLeaveTypeFilterCards({
    leaveTypes,
    selectedId,
    onSelect,
}: {
    leaveTypes: BalanceLeaveTypeOption[];
    selectedId: string;
    onSelect: (leaveTypeId: string) => void;
}) {
    if (leaveTypes.length === 0) {
        return null;
    }

    return (
        <div
            className="grid grid-cols-[repeat(auto-fit,minmax(10.5rem,1fr))] gap-3"
            role="group"
            aria-label="Filter by leave type"
        >
            <button
                type="button"
                onClick={() => onSelect('')}
                aria-pressed={selectedId === ''}
                className="h-full w-full rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <Card
                    className={cn(
                        'h-full transition-colors',
                        selectedId === '' && 'border-primary/40 bg-primary/5',
                    )}
                >
                    <CardContent className="p-3">
                        <p className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Leave type
                        </p>
                        <p className="mt-1 text-sm font-semibold tracking-tight">
                            All leave types
                        </p>
                    </CardContent>
                </Card>
            </button>

            {leaveTypes.map((leaveType) => {
                const id = String(leaveType.id);
                const isActive = selectedId === id;

                return (
                    <button
                        key={leaveType.id}
                        type="button"
                        onClick={() => onSelect(isActive ? '' : id)}
                        aria-pressed={isActive}
                        aria-label={
                            isActive
                                ? `Clear ${leaveType.name} filter and show all leave types`
                                : `Filter by ${leaveType.name}`
                        }
                        className="h-full w-full rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <Card
                            className={cn(
                                'h-full transition-colors',
                                isActive && 'border-primary/40 bg-primary/5',
                            )}
                        >
                            <CardContent className="p-3">
                                <p className="line-clamp-2 text-sm font-semibold tracking-tight">
                                    {leaveType.name}
                                </p>
                                {leaveType.code ? (
                                    <p className="mt-1 font-mono text-[11px] text-muted-foreground">
                                        {leaveType.code}
                                    </p>
                                ) : (
                                    <p className="mt-1 text-[11px] text-muted-foreground">
                                        Leave type
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </button>
                );
            })}
        </div>
    );
}
