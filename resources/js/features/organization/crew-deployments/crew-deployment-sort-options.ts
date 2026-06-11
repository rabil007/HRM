export const DEFAULT_DEPLOYMENT_SORT = 'joined_date';

export const DEFAULT_DEPLOYMENT_SORT_DIRECTION = 'desc';

export const DEPLOYMENT_SORT_OPTIONS = [
    { value: 'joined_date', label: 'Joined date' },
    { value: 'employee_no', label: 'Employee no.' },
    { value: 'employee_name', label: 'Name' },
    { value: 'rank', label: 'Rank' },
    { value: 'nationality', label: 'Nationality' },
    { value: 'vessel_name', label: 'Vessel' },
    { value: 'hire_date', label: 'Date of hire' },
    { value: 'arrived_date', label: 'Arrived' },
    { value: 'join_standby_from', label: 'Join standby from' },
    { value: 'join_standby_to', label: 'Join standby to' },
    { value: 'join_standby_days', label: 'Join standby days' },
    { value: 'disembarked_date', label: 'Disembarked' },
    { value: 'vessel_days', label: 'Vessel days' },
    { value: 'leave_standby_from', label: 'Leave standby from' },
    { value: 'leave_standby_to', label: 'Leave standby to' },
    { value: 'leave_standby_days', label: 'Leave standby days' },
    { value: 'travelled_date', label: 'Travelled' },
    { value: 'sponsor', label: 'Sponsor' },
    { value: 'client', label: 'Client' },
    { value: 'created_at', label: 'Date added' },
] as const;

export type DeploymentSortField = (typeof DEPLOYMENT_SORT_OPTIONS)[number]['value'];
