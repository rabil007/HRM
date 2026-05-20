import type { ReactElement } from 'react';
import {
    importMethod as importSeaService,
    importTemplate as seaServiceImportTemplate,
} from '@/actions/App/Http/Controllers/Organization/EmployeeSeaServiceController';
import {
    importMethod as importVaccination,
    importTemplate as vaccinationImportTemplate,
} from '@/actions/App/Http/Controllers/Organization/EmployeeVaccinationController';
import {
    importMethod as importWorkExperience,
    importTemplate as workExperienceImportTemplate,
} from '@/actions/App/Http/Controllers/Organization/EmployeeWorkExperienceController';
import type { EmployeeRecordImportDialogProps } from '@/features/organization/employees/profile/components/employee-record-import-dialog';

type RecordImportConfig = Pick<
    EmployeeRecordImportDialogProps,
    | 'inputId'
    | 'title'
    | 'description'
    | 'templateHint'
    | 'columnHelp'
    | 'reloadOnly'
> & {
    importUrl: (employeeId: number) => string;
    templateUrl: (employeeId: number) => string;
};

function columnList(items: ReactElement[]): ReactElement {
    return (
        <ul className="list-inside list-disc space-y-1 text-muted-foreground">{items}</ul>
    );
}

function columnItem(label: string, detail: string): ReactElement {
    return (
        <li>
            <span className="font-medium text-foreground">{label}</span> — {detail}
        </li>
    );
}

export function vaccinationImportConfig(employeeId: number): RecordImportConfig {
    return {
        inputId: `vaccination-import-${employeeId}`,
        title: 'Import vaccinations',
        description:
            'New rows are added to this profile. Country must match an active country name in your master list when provided.',
        templateHint: 'Use the sample headers. Dates are optional on each row.',
        reloadOnly: ['vaccinations'],
        importUrl: (id) => importVaccination.url({ employee: id }),
        templateUrl: (id) => vaccinationImportTemplate.url({ employee: id }),
        columnHelp: columnList([
            columnItem(
                'vaccination_name',
                'required (aliases: Vaccination, Vaccine, Immunization)',
            ),
            columnItem(
                'country',
                'optional; name must match master data (e.g. United Arab Emirates)',
            ),
            columnItem(
                'first_dose, second_dose, booster_dose',
                'optional dates (YYYY-MM-DD or locale formats Carbon accepts)',
            ),
        ]),
    };
}

export function workExperienceImportConfig(employeeId: number): RecordImportConfig {
    return {
        inputId: `work-experience-import-${employeeId}`,
        title: 'Import work experience',
        description:
            'Rows are appended to this employee’s history. Omit date_to for ongoing roles when the spreadsheet column is present.',
        templateHint:
            'Use the sample headers and date format (YYYY-MM-DD or common locale dates).',
        reloadOnly: ['work_experiences'],
        importUrl: (id) => importWorkExperience.url({ employee: id }),
        templateUrl: (id) => workExperienceImportTemplate.url({ employee: id }),
        columnHelp: columnList([
            columnItem('company_name', 'required (aliases: Company name, Employer)'),
            columnItem('job_title', 'required (aliases: Job title, Role, Position)'),
            columnItem('date_from', 'required parseable date (aliases: Start date, From)'),
            columnItem('date_to', 'optional (aliases: End date, To)'),
            columnItem('responsibility', 'optional (aliases: Duties, Description)'),
        ]),
    };
}

export function seaServiceImportConfig(employeeId: number): RecordImportConfig {
    return {
        inputId: `sea-service-import-${employeeId}`,
        title: 'Import sea service',
        description:
            "Rows are appended to this employee's sea service history. Vessel type and rank must match active master data names exactly.",
        templateHint:
            'Download the sample CSV, then fill in rows using your master data names.',
        reloadOnly: ['sea_services'],
        importUrl: (id) => importSeaService.url({ employee: id }),
        templateUrl: (id) => seaServiceImportTemplate.url({ employee: id }),
        columnHelp: columnList([
            columnItem('vessel_type', 'required (aliases: Vessel type, Type)'),
            columnItem('vessel_name', 'required (aliases: Vessel name, Vessel)'),
            columnItem('rank', 'required (aliases: Rank name, Position)'),
            columnItem('start_date', 'required (aliases: Start date, Date from)'),
            columnItem('end_date', 'required (aliases: End date, Date to)'),
            columnItem('grt', 'optional'),
            columnItem('bhp', 'optional'),
            columnItem('client', 'optional (must match an active client name)'),
            columnItem('is_offshore', 'optional (yes / no / true / false / 1 / 0)'),
        ]),
    };
}
