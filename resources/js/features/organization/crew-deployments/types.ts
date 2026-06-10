export type DeploymentItem = {
    id: number;
    employee_id: number;
    employee_no: string | null;
    employee_name: string | null;
    nationality: string | null;
    rank_id: number | null;
    rank_name: string | null;
    client_id: number | null;
    client_name: string | null;
    company_visa_type_id: number | null;
    company_visa_type_name: string | null;
    vessel_name: string | null;
    hire_date: string | null;
    arrived_date: string | null;
    standby_from: string | null;
    standby_to: string | null;
    standby_days: number | null;
    joined_date: string | null;
    disembarked_date: string | null;
    travelled_date: string | null;
    total_days: number | null;
    remarks: string | null;
    status: string;
    status_label: string;
    current_vessel: string | null;
};

export type DeploymentSummary = Record<string, number>;
