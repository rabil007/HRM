export function myLeaveState(
    linkedEmployeeId: number | null,
    attendanceLeaveEnabled: boolean,
    canCreate: boolean,
) {
    if (linkedEmployeeId === null) {
        return {
            title: 'Your account is not linked to an employee record.',
            description:
                'Ask HR or an administrator to link your user account to your employee profile before requesting leave.',
            canRequestLeave: false,
            showBalances: false,
        };
    }

    if (!attendanceLeaveEnabled) {
        return {
            title: 'Attendance & Leave is not enabled for your department.',
            description:
                'Your current department is excluded from Attendance and Leave. Contact HR if this should be enabled.',
            canRequestLeave: false,
            showBalances: false,
        };
    }

    return {
        title: 'You have no leave requests yet.',
        description: undefined,
        canRequestLeave: canCreate,
        showBalances: true,
    };
}
