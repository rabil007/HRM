/** Backend-aligned default required keys when no profile template is assigned. */
export const TEMPLATE_RECORD_DEFAULT_REQUIRED: Record<string, string[]> = {
    employee_contracts: ['start_date', 'status'],
    employee_bank_accounts: [],
    employee_education_qualifications: ['certificate'],
    employee_work_experiences: ['company_name', 'job_title', 'date_from'],
    employee_languages: ['language_name'],
    employee_trainings: ['course_id', 'issue_date', 'institute_center'],
    employee_sea_services: [
        'vessel_type_id',
        'vessel_id',
        'rank_id',
        'start_date',
        'end_date',
    ],
    employee_vaccinations: ['vaccination_name'],
    employee_documents: ['document_type_id'],
};
