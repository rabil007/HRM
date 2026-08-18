import { MobileRecordCard } from '@/components/mobile-record-list';
import type { MobileRecordOverflowAction } from '@/components/mobile-record-list';
import { RecordStatusBadge } from '@/features/attendance/records/components/record-status-badge';
import { attendanceRecordMobileCardModel } from '@/features/attendance/records/lib/attendance-record-mobile-card';
import { formatDisplayDate, formatDisplayTime12h } from '@/lib/format-date';
import type { AttendanceRecord, AttendanceRecordPermissions } from '../types';

export function AttendanceRecordMobileCard({
    record,
    can,
    onEdit,
    onDelete,
}: {
    record: AttendanceRecord;
    can: Pick<AttendanceRecordPermissions, 'update' | 'delete' | 'manage'>;
    onEdit: (record: AttendanceRecord) => void;
    onDelete: (record: AttendanceRecord) => void;
}) {
    const model = attendanceRecordMobileCardModel(record, can);
    const overflowActions: MobileRecordOverflowAction[] = [];

    if (model.showDelete) {
        overflowActions.push({
            key: 'delete',
            label: 'Delete',
            destructive: true,
            onSelect: () => onDelete(record),
        });
    }

    return (
        <MobileRecordCard
            title={
                model.showEmployeeIdentity
                    ? model.title
                    : formatDisplayDate(model.title)
            }
            subtitle={model.subtitle ? formatDisplayDate(model.subtitle) : null}
            meta={[
                record.clock_in || record.clock_out
                    ? `${formatDisplayTime12h(record.clock_in)} – ${formatDisplayTime12h(record.clock_out)}`
                    : null,
                record.hours_worked !== null && record.hours_worked !== ''
                    ? `${record.hours_worked} hrs`
                    : null,
            ]}
            status={<RecordStatusBadge status={model.status} />}
            attention={model.attention}
            primaryAction={
                model.showEdit
                    ? {
                          label: 'Edit',
                          onClick: () => onEdit(record),
                      }
                    : undefined
            }
            overflowActions={overflowActions}
        />
    );
}
