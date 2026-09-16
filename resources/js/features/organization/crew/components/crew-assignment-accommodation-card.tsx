import type { ReactElement } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { CrewAccommodationSummaryItem } from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';

export function CrewAssignmentAccommodationCard({
    items,
}: {
    items: CrewAccommodationSummaryItem[];
}): ReactElement | null {
    if (items.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Accommodation</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {items.map((item) => (
                    <div
                        key={item.id}
                        className="rounded-lg border border-border/60 p-4"
                    >
                        <div className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {item.stay_type_label}
                        </div>

                        {item.accommodation_status === 'no_accommodation' ? (
                            <p className="mt-2 text-sm">
                                No hotel accommodation
                            </p>
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
                                        {item.stay_days !== null
                                            ? ` · ${item.stay_days} day${
                                                  item.stay_days === 1
                                                      ? ''
                                                      : 's'
                                              }`
                                            : ''}
                                    </div>
                                ) : null}
                            </div>
                        )}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
