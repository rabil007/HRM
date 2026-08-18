import { show as leaveRequestShow } from '@/actions/App/Http/Controllers/Attendance/LeaveRequestController';
import { MobileRecordCard } from '@/components/mobile-record-list';
import type { MobileRecordOverflowAction } from '@/components/mobile-record-list';
import { LeaveRequestStatusBadge } from '@/features/attendance/leave-requests/components/leave-request-status-badge';
import { leaveRequestMobileCardModel } from '@/features/attendance/leave-requests/lib/leave-request-mobile-card';
import { formatDisplayDate } from '@/lib/format-date';
import type { LeaveRequest } from '../types';

export function LeaveRequestMobileCard({
    leaveRequest,
    onEdit,
    onDelete,
    onAdministrativeDelete,
    onApprove,
    onReject,
    onCancel,
}: {
    leaveRequest: LeaveRequest;
    onEdit: (leaveRequest: LeaveRequest) => void;
    onDelete: (leaveRequest: LeaveRequest) => void;
    onAdministrativeDelete?: (leaveRequest: LeaveRequest) => void;
    onApprove: (leaveRequest: LeaveRequest) => void;
    onReject: (leaveRequest: LeaveRequest) => void;
    onCancel: (leaveRequest: LeaveRequest) => void;
}) {
    const model = leaveRequestMobileCardModel(leaveRequest);
    const viewHref = leaveRequestShow.url(leaveRequest.id);
    const overflowActions: MobileRecordOverflowAction[] = [];

    if (model.showApprove) {
        overflowActions.push({
            key: 'reject',
            label: 'Reject',
            destructive: true,
            onSelect: () => onReject(leaveRequest),
        });
    }

    if (model.showCancel) {
        overflowActions.push({
            key: 'cancel',
            label: 'Cancel',
            onSelect: () => onCancel(leaveRequest),
        });
    }

    if (model.showEdit) {
        overflowActions.push({
            key: 'edit',
            label: 'Edit',
            onSelect: () => onEdit(leaveRequest),
        });
    }

    if (model.showDelete) {
        overflowActions.push({
            key: 'delete',
            label: 'Delete',
            destructive: true,
            onSelect: () => onDelete(leaveRequest),
        });
    }

    if (model.showAdministrativeDelete && onAdministrativeDelete) {
        overflowActions.push({
            key: 'void',
            label: 'Void and remove',
            destructive: true,
            onSelect: () => onAdministrativeDelete(leaveRequest),
        });
    }

    return (
        <MobileRecordCard
            title={model.title}
            subtitle={model.subtitle}
            meta={[
                `${formatDisplayDate(model.startDate)} – ${formatDisplayDate(model.endDate)}`,
                model.duration,
            ]}
            status={<LeaveRequestStatusBadge status={model.status} />}
            href={viewHref}
            primaryAction={
                model.showApprove
                    ? {
                          label: 'Approve',
                          onClick: () => onApprove(leaveRequest),
                      }
                    : { label: 'Open', href: viewHref }
            }
            overflowActions={overflowActions}
        />
    );
}
