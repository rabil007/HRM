import type {
    ApprovalLocationOption,
    ClientOption,
    CompanyVisaTypeOption,
    CountryOption,
    DepartmentTreeNode,
    GenderOption,
    ManagerOption,
    PositionOption,
    ProjectOption,
    RoleOption,
    SssaOption,
    VisaTypeOption,
} from '../types.ts';
import { resolveProjectOnClientChange } from './employee-client-project-filter.ts';
import type { EmployeeFilters } from './employee-client-project-filter.ts';
import {
    completenessChips,
    removeCompletenessKey,
    STATUS_OPTION_LABELS,
} from './employee-smart-search.ts';

export type EmployeeFilterOptions = {
    department_tree?: DepartmentTreeNode[];
    positions?: PositionOption[];
    managers?: ManagerOption[];
    genders?: GenderOption[];
    countries?: CountryOption[];
    visaTypes?: VisaTypeOption[];
    companyVisaTypes?: CompanyVisaTypeOption[];
    approvalLocations?: ApprovalLocationOption[];
    sssaOptions?: SssaOption[];
    clients?: ClientOption[];
    projects?: ProjectOption[];
    roles?: RoleOption[];
};

export type EmployeeActiveFilterChip = {
    key: string;
    label: string;
    ariaLabel: string;
    onClear: () => void;
};

/**
 * Recursively find the department name by ID from the department tree.
 */
export function findDepartmentName(
    nodes: DepartmentTreeNode[] | undefined,
    id: string | number,
): string | null {
    if (!nodes || id === '' || id === null || id === undefined) {
        return null;
    }

    const targetId = Number(id);

    if (Number.isNaN(targetId)) {
        return null;
    }

    for (const node of nodes) {
        if (node.id === targetId) {
            return node.name;
        }

        if (node.children && node.children.length > 0) {
            const found = findDepartmentName(node.children, targetId);

            if (found !== null) {
                return found;
            }
        }
    }

    return null;
}

export function clearDepartmentFilter(
    filters: EmployeeFilters,
): EmployeeFilters {
    return {
        ...filters,
        department_id: '',
        department_ids: '',
        position_id: '',
    };
}

function csvIds(csv: string): string[] {
    return csv
        .split(',')
        .map((id) => id.trim())
        .filter((id) => id !== '');
}

export function clearPositionFilter(filters: EmployeeFilters): EmployeeFilters {
    return {
        ...filters,
        position_id: '',
    };
}

export function clearClientFilter(
    filters: EmployeeFilters,
    projects?: ProjectOption[],
): EmployeeFilters {
    return {
        ...filters,
        client_id: '',
        project_id: resolveProjectOnClientChange(
            filters.project_id,
            '',
            projects,
        ),
    };
}

export function clearApprovalLocationFilter(
    filters: EmployeeFilters,
    idToRemove: string,
): EmployeeFilters {
    const nextIds = filters.approval_location_id
        .split(',')
        .map((v) => v.trim())
        .filter((v) => v !== '' && v !== idToRemove);

    return {
        ...filters,
        approval_location_id: nextIds.join(','),
    };
}

export function clearSssaOptionFilter(
    filters: EmployeeFilters,
    idToRemove: string,
): EmployeeFilters {
    const nextIds = filters.sssa_option_id
        .split(',')
        .map((v) => v.trim())
        .filter((v) => v !== '' && v !== idToRemove);

    return {
        ...filters,
        sssa_option_id: nextIds.join(','),
    };
}

export function hasActiveEmployeeFilters(
    filters: EmployeeFilters,
    search?: string,
): boolean {
    if (search && search.trim() !== '') {
        return true;
    }

    if (filters.status && filters.status.trim() !== '') {
        return true;
    }

    for (const [key, value] of Object.entries(filters)) {
        if (key === 'status') {
            continue;
        }

        if (typeof value === 'string' && value.trim() !== '') {
            return true;
        }
    }

    return false;
}

export function buildEmployeeActiveFilterChips({
    filters,
    searchInput,
    options,
    onClearSearch,
    onApplyFilters,
}: {
    filters: EmployeeFilters;
    searchInput?: string;
    options: EmployeeFilterOptions;
    onClearSearch?: () => void;
    onApplyFilters: (next: EmployeeFilters) => void;
}): EmployeeActiveFilterChip[] {
    const chips: EmployeeActiveFilterChip[] = [];

    // 1. Search
    if (searchInput && searchInput.trim() !== '' && onClearSearch) {
        const trimmed = searchInput.trim();
        const label = `Search: ${trimmed}`;

        chips.push({
            key: 'search',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: onClearSearch,
        });
    }

    // 2. Department
    const selectedDepartmentIds = filters.department_ids
        ? csvIds(filters.department_ids)
        : filters.department_id && filters.department_id.trim() !== ''
          ? [filters.department_id]
          : [];

    if (selectedDepartmentIds.length > 0) {
        const departmentNames = selectedDepartmentIds.map(
            (id) =>
                findDepartmentName(options.department_tree, id)?.trim() ||
                'Unknown',
        );
        const label =
            departmentNames.length === 1
                ? `Department: ${departmentNames[0]}`
                : `Departments: ${departmentNames.slice(0, 2).join(', ')}${
                      departmentNames.length > 2
                          ? ` +${departmentNames.length - 2}`
                          : ''
                  }`;

        chips.push({
            key: 'department_ids',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () => onApplyFilters(clearDepartmentFilter(filters)),
        });
    }

    // 3. Position
    if (filters.position_id && filters.position_id.trim() !== '') {
        const position = options.positions?.find(
            (p) => String(p.id) === filters.position_id,
        );
        const posTitle = position?.title?.trim() || 'Unknown';
        const label = `Position: ${posTitle}`;

        chips.push({
            key: 'position_id',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () => onApplyFilters(clearPositionFilter(filters)),
        });
    }

    // 4. HR Status (only when explicitly set, not default '')
    if (filters.status && filters.status.trim() !== '') {
        const statusLabel =
            STATUS_OPTION_LABELS[filters.status] ?? filters.status;
        const label = `HR Status: ${statusLabel}`;

        chips.push({
            key: 'status',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () => onApplyFilters({ ...filters, status: '' }),
        });
    }

    // 5. Manager
    if (filters.manager_id && filters.manager_id.trim() !== '') {
        const manager = options.managers?.find(
            (m) => String(m.id) === filters.manager_id,
        );
        const mgrName = manager?.name?.trim() || 'Unknown';
        const label = `Manager: ${mgrName}`;

        chips.push({
            key: 'manager_id',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () => onApplyFilters({ ...filters, manager_id: '' }),
        });
    }

    // 6. Role
    if (filters.role_id && filters.role_id.trim() !== '') {
        const role = options.roles?.find(
            (r) => String(r.id) === filters.role_id,
        );
        const roleName = role?.name?.trim() || 'Unknown';
        const label = `Role: ${roleName}`;

        chips.push({
            key: 'role_id',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () => onApplyFilters({ ...filters, role_id: '' }),
        });
    }

    // 7. Client
    if (filters.client_id && filters.client_id.trim() !== '') {
        const client = options.clients?.find(
            (c) => String(c.id) === filters.client_id,
        );
        const clientName = client?.name?.trim() || 'Unknown';
        const label = `Client: ${clientName}`;

        chips.push({
            key: 'client_id',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () =>
                onApplyFilters(clearClientFilter(filters, options.projects)),
        });
    }

    // 8. Project
    if (filters.project_id && filters.project_id.trim() !== '') {
        const project = options.projects?.find(
            (p) => String(p.id) === filters.project_id,
        );
        const projectTitle = project?.title?.trim() || 'Unknown';
        const label = `Project: ${projectTitle}`;

        chips.push({
            key: 'project_id',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () => onApplyFilters({ ...filters, project_id: '' }),
        });
    }

    // 10. Nationality
    if (filters.nationality_id && filters.nationality_id.trim() !== '') {
        const country = options.countries?.find(
            (c) => String(c.id) === filters.nationality_id,
        );
        const countryName = country?.name?.trim() || 'Unknown';
        const label = `Nationality: ${countryName}`;

        chips.push({
            key: 'nationality_id',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () => onApplyFilters({ ...filters, nationality_id: '' }),
        });
    }

    // 11. Gender
    if (filters.gender_id && filters.gender_id.trim() !== '') {
        const gender = options.genders?.find(
            (g) => String(g.id) === filters.gender_id,
        );
        const genderName = gender?.name?.trim() || 'Unknown';
        const label = `Gender: ${genderName}`;

        chips.push({
            key: 'gender_id',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () => onApplyFilters({ ...filters, gender_id: '' }),
        });
    }

    // 12. Visa Type
    if (filters.visa_type_id && filters.visa_type_id.trim() !== '') {
        const visaType = options.visaTypes?.find(
            (v) => String(v.id) === filters.visa_type_id,
        );
        const visaName = visaType?.name?.trim() || 'Unknown';
        const label = `Visa Type: ${visaName}`;

        chips.push({
            key: 'visa_type_id',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () => onApplyFilters({ ...filters, visa_type_id: '' }),
        });
    }

    // 13. Sponsor (Company Visa Type)
    if (
        filters.company_visa_type_id &&
        filters.company_visa_type_id.trim() !== ''
    ) {
        const companyVisa = options.companyVisaTypes?.find(
            (v) => String(v.id) === filters.company_visa_type_id,
        );
        const sponsorName = companyVisa?.name?.trim() || 'Unknown';
        const label = `Sponsor: ${sponsorName}`;

        chips.push({
            key: 'company_visa_type_id',
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () =>
                onApplyFilters({ ...filters, company_visa_type_id: '' }),
        });
    }

    // 14. Approval Locations (CSV)
    if (
        filters.approval_location_id &&
        filters.approval_location_id.trim() !== ''
    ) {
        const ids = Array.from(
            new Set(
                filters.approval_location_id
                    .split(',')
                    .map((v) => v.trim())
                    .filter((v) => v !== ''),
            ),
        );

        for (const id of ids) {
            const location = options.approvalLocations?.find(
                (l) => String(l.id) === id,
            );
            const locName = location?.name?.trim() || 'Unknown';
            const label = `Approval Location: ${locName}`;

            chips.push({
                key: `approval_location_id:${id}`,
                label,
                ariaLabel: `Remove ${label} filter`,
                onClear: () =>
                    onApplyFilters(clearApprovalLocationFilter(filters, id)),
            });
        }
    }

    // 15. SSSA Options (CSV)
    if (filters.sssa_option_id && filters.sssa_option_id.trim() !== '') {
        const ids = Array.from(
            new Set(
                filters.sssa_option_id
                    .split(',')
                    .map((v) => v.trim())
                    .filter((v) => v !== ''),
            ),
        );

        for (const id of ids) {
            const option = options.sssaOptions?.find(
                (o) => String(o.id) === id,
            );
            const sssaName = option?.name?.trim() || 'Unknown';
            const label = `SSSA: ${sssaName}`;

            chips.push({
                key: `sssa_option_id:${id}`,
                label,
                ariaLabel: `Remove ${label} filter`,
                onClear: () =>
                    onApplyFilters(clearSssaOptionFilter(filters, id)),
            });
        }
    }

    // 16. Completeness Chips
    for (const chip of completenessChips(filters)) {
        const [concept, operator] = chip.key.split(':');
        const label = `${chip.label} · ${chip.title}`;

        chips.push({
            key: `completeness:${chip.key}`,
            label,
            ariaLabel: `Remove ${label} filter`,
            onClear: () =>
                onApplyFilters(
                    removeCompletenessKey(
                        filters,
                        operator === 'present' ? 'present' : 'missing',
                        concept,
                    ),
                ),
        });
    }

    return chips;
}
