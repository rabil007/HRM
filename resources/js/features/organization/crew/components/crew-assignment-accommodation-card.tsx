import type { ReactElement } from 'react';
import {
    CrewAssignmentCollapsibleRecord,
    recordStatusBadge,
} from '@/features/organization/crew/components/crew-assignment-collapsible-record';
import type { CrewAccommodationSummaryItem } from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';

function accommodationSummary(items: CrewAccommodationSummaryItem[]): string {
    const open = items.filter((item) => item.is_open).length;
    const hotel = items.filter(
        (item) => item.accommodation_status !== 'no_accommodation',
    ).length;

    if (open > 0) {
        return `${open} open stay${open === 1 ? '' : 's'} · ${items.length} record${items.length === 1 ? '' : 's'}`;
    }

    if (hotel > 0) {
        return `${hotel} hotel record${hotel === 1 ? '' : 's'}`;
    }

    return `${items.length} record${items.length === 1 ? '' : 's'}`;
}

export function CrewAssignmentAccommodationCard({
    items,
    defaultOpen,
}: {
    items: CrewAccommodationSummaryItem[];
    defaultOpen?: boolean;
}): ReactElement | null {
    if (items.length === 0) {
        return null;
    }

    const hasOpenStay = items.some((item) => item.is_open);
    const openByDefault = defaultOpen ?? hasOpenStay;

    return (
        <CrewAssignmentCollapsibleRecord
            title="Accommodation Records"
            summary={accommodationSummary(items)}
            badges={
                hasOpenStay
                    ? recordStatusBadge('Open stay', 'warning')
                    : recordStatusBadge(
                          `${items.length} record${items.length === 1 ? '' : 's'}`,
                          'secondary',
                      )
            }
            defaultOpen={openByDefault}
            contentClassName="space-y-4 pt-4"
            data-slot="assignment-accommodation"
        >
            {items.map((item) => (
                <div
                    key={item.id}
                    className="rounded-lg border border-border/60 p-4"
                >
                    <div className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {item.stay_type_label}
                    </div>

                    {item.accommodation_status === 'no_accommodation' ? (
                        <p className="mt-2 text-sm">No hotel accommodation</p>
                    ) : (
                        <div className="mt-2 space-y-2 text-sm">
                            <div className="font-medium">
                                {item.hotel_name ?? 'Hotel'}
                            </div>
                            {item.room_type_name ? (
                                <div className="text-muted-foreground">
                                    {item.room_type_name}
                                </div>
                            ) : null}
                            {item.check_in_date ? (
                                <div>
                                    <span className="text-muted-foreground">
                                        Check-in
                                    </span>
                                    <div>
                                        {formatDisplayDate(item.check_in_date)}
                                    </div>
                                </div>
                            ) : null}
                            {item.check_out_date ? (
                                <div>
                                    <span className="text-muted-foreground">
                                        Check-out
                                    </span>
                                    <div>
                                        {formatDisplayDate(item.check_out_date)}
                                    </div>
                                </div>
                            ) : item.is_open ? (
                                <div className="text-muted-foreground">
                                    Currently staying
                                    {item.stay_days !== null
                                        ? ` · ${item.stay_days} day${
                                              item.stay_days === 1 ? '' : 's'
                                          }`
                                        : ''}
                                </div>
                            ) : null}
                        </div>
                    )}
                </div>
            ))}
        </CrewAssignmentCollapsibleRecord>
    );
}
