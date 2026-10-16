import { Pencil } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { CrewAccommodationStayEditSheet } from '@/features/organization/crew/components/crew-accommodation-stay-edit-sheet';
import {
    CrewAssignmentCollapsibleRecord,
    recordStatusBadge,
} from '@/features/organization/crew/components/crew-assignment-collapsible-record';
import type {
    CrewAccommodationSummaryItem,
    CrewAssignmentFormOptions,
} from '@/features/organization/crew/types';
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

function roomTypeLabel(item: CrewAccommodationSummaryItem): string {
    if (item.room_type_display) {
        return item.room_type_display;
    }

    if (item.room_type_name) {
        return item.room_type_name;
    }

    if (item.room_type_id != null) {
        return 'Unavailable';
    }

    return 'Not specified';
}

function hotelLabel(item: CrewAccommodationSummaryItem): string {
    if (item.hotel_display) {
        return item.hotel_display;
    }

    if (item.hotel_name) {
        return item.hotel_name;
    }

    if (item.hotel_id != null) {
        return 'Unavailable';
    }

    return 'Hotel';
}

export function CrewAssignmentAccommodationCard({
    items,
    defaultOpen,
    assignmentId,
    canEdit = false,
    formOptions,
}: {
    items: CrewAccommodationSummaryItem[];
    defaultOpen?: boolean;
    assignmentId?: number;
    canEdit?: boolean;
    formOptions?: CrewAssignmentFormOptions;
}): ReactElement | null {
    const [editingStay, setEditingStay] =
        useState<CrewAccommodationSummaryItem | null>(null);

    if (items.length === 0) {
        return null;
    }

    const hasOpenStay = items.some((item) => item.is_open);
    const openByDefault = defaultOpen ?? hasOpenStay;

    return (
        <>
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
                        className="relative rounded-lg border border-border/60 p-4"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                {item.stay_type_label}
                            </div>
                            {canEdit &&
                            item.accommodation_status === 'hotel' &&
                            assignmentId != null ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-8 shrink-0 gap-1.5 px-2 text-xs"
                                    onClick={() => setEditingStay(item)}
                                    data-slot="accommodation-edit-button"
                                >
                                    <Pencil className="size-3.5" aria-hidden />
                                    Edit
                                </Button>
                            ) : null}
                        </div>

                        {item.accommodation_status === 'no_accommodation' ? (
                            <p className="mt-2 text-sm">
                                No hotel accommodation
                            </p>
                        ) : (
                            <div className="mt-2 space-y-2 text-sm">
                                <div>
                                    <span className="text-muted-foreground">
                                        Hotel
                                    </span>
                                    <div className="font-medium">
                                        {hotelLabel(item)}
                                    </div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Room Type
                                    </span>
                                    <div>{roomTypeLabel(item)}</div>
                                </div>
                                {item.check_in_date ? (
                                    <div>
                                        <span className="text-muted-foreground">
                                            Check-in
                                        </span>
                                        <div>
                                            {formatDisplayDate(
                                                item.check_in_date,
                                            )}
                                        </div>
                                    </div>
                                ) : null}
                                {item.check_out_date ? (
                                    <div>
                                        <span className="text-muted-foreground">
                                            Check-out
                                        </span>
                                        <div>
                                            {formatDisplayDate(
                                                item.check_out_date,
                                            )}
                                        </div>
                                    </div>
                                ) : item.is_open ? (
                                    <div className="text-muted-foreground">
                                        Currently staying
                                    </div>
                                ) : null}
                                {item.accommodation_status === 'hotel' &&
                                (item.stay_days_label ||
                                    item.stay_days !== null) ? (
                                    <div
                                        className={
                                            item.stay_days_invalid
                                                ? 'text-destructive'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        Hotel days:{' '}
                                        {item.stay_days_label ??
                                            `${item.stay_days} day${
                                                item.stay_days === 1 ? '' : 's'
                                            }`}
                                    </div>
                                ) : null}
                            </div>
                        )}
                    </div>
                ))}
            </CrewAssignmentCollapsibleRecord>

            {assignmentId != null ? (
                <CrewAccommodationStayEditSheet
                    open={editingStay !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setEditingStay(null);
                        }
                    }}
                    assignmentId={assignmentId}
                    stay={editingStay}
                    formOptions={formOptions}
                />
            ) : null}
        </>
    );
}
