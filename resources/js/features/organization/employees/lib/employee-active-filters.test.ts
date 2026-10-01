import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
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
    PositionOption,
    RoleOption,
    SssaOption,
    VisaTypeOption,
} from '../types.ts';
import {
    buildEmployeeActiveFilterChips,
    clearApprovalLocationFilter,
    clearClientFilter,
    clearDepartmentFilter,
    clearPositionFilter,
    clearSssaOptionFilter,
    findDepartmentName,
    hasActiveEmployeeFilters,
} from './employee-active-filters.ts';
import type { EmployeeFilterOptions } from './employee-active-filters.ts';
import { EMPTY_EMPLOYEE_FILTERS } from './employee-client-project-filter.ts';
import type { EmployeeFilters } from './employee-client-project-filter.ts';

const mockDepartmentTree: DepartmentTreeNode[] = [
    {
        id: null,
        name: 'All Departments',
        count: 10,
        children: [
            {
                id: 1,
                name: 'Head Office',
                count: 8,
                children: [
                    {
                        id: 10,
                        name: 'Office',
                        count: 5,
                        children: [
                            {
                                id: 11,
                                name: 'Finance',
                                count: 2,
                                children: [],
                                positions: [],
                            },
                        ],
                        positions: [],
                    },
                ],
                positions: [],
            },
        ],
        positions: [],
    },
];

const mockPositions: PositionOption[] = [
    { id: 4, department_id: 10, title: 'Accountant' },
    { id: 5, department_id: 10, title: 'HR Officer' },
];

const mockManagers: ManagerOption[] = [
    { id: 1, employee_no: 'EMP-001', name: 'Mohammed Rabil' },
];

const mockRoles: RoleOption[] = [{ id: 2, name: 'HR Manager' }];

const mockClients: ClientOption[] = [{ id: 3, name: 'ADNOC' }];

const mockProjects: ProjectOption[] = [
    { id: 8, title: 'Project A', client_id: 3 },
    { id: 9, title: 'Project B', client_id: 3 },
    { id: 10, title: 'Internal Project', client_id: null },
];

const mockRanks: PositionOption[] = [{ id: 5, name: 'AB' }];

const mockCountries: CountryOption[] = [
    { id: 101, name: 'India', code: 'IN', dial_code: '+91' },
];

const mockGenders: GenderOption[] = [{ id: 1, name: 'Male' }];

const mockVisaTypes: VisaTypeOption[] = [{ id: 2, name: 'Employment' }];

const mockCompanyVisaTypes: CompanyVisaTypeOption[] = [{ id: 3, name: 'OMS' }];

const mockApprovalLocations: ApprovalLocationOption[] = [
    { id: 1, name: 'Abu Dhabi' },
    { id: 3, name: 'Dubai' },
];

const mockSssaOptions: SssaOption[] = [
    { id: 2, name: 'Option A' },
    { id: 4, name: 'Option C' },
];

const mockOptions: EmployeeFilterOptions = {
    department_tree: mockDepartmentTree,
    positions: mockPositions,
    managers: mockManagers,
    roles: mockRoles,
    clients: mockClients,
    projects: mockProjects,
    ranks: mockRanks,
    countries: mockCountries,
    genders: mockGenders,
    visaTypes: mockVisaTypes,
    companyVisaTypes: mockCompanyVisaTypes,
    approvalLocations: mockApprovalLocations,
    sssaOptions: mockSssaOptions,
};

describe('employee active filters - findDepartmentName', () => {
    it('resolves recursive department name by ID', () => {
        assert.equal(findDepartmentName(mockDepartmentTree, 10), 'Office');
        assert.equal(findDepartmentName(mockDepartmentTree, '10'), 'Office');
        assert.equal(findDepartmentName(mockDepartmentTree, 11), 'Finance');
        assert.equal(findDepartmentName(mockDepartmentTree, 1), 'Head Office');
    });

    it('returns null for missing, unknown, or invalid department ID', () => {
        assert.equal(findDepartmentName(mockDepartmentTree, 999), null);
        assert.equal(findDepartmentName(mockDepartmentTree, ''), null);
        assert.equal(findDepartmentName(undefined, 10), null);
        assert.equal(findDepartmentName(mockDepartmentTree, 'invalid'), null);
    });
});

describe('employee active filters - chip generation & label resolution', () => {
    it('resolves Department filter label from recursive tree', () => {
        const filters: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            department_id: '10',
        };
        const chips = buildEmployeeActiveFilterChips({
            filters,
            options: mockOptions,
            onApplyFilters: () => {},
        });

        assert.equal(chips.length, 1);
        assert.equal(chips[0].key, 'department_ids');
        assert.equal(chips[0].label, 'Department: Office');
        assert.equal(chips[0].ariaLabel, 'Remove Department: Office filter');
    });

    it('resolves multiple Department filter labels from recursive tree', () => {
        const filters: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            department_ids: '10,11',
        };
        const chips = buildEmployeeActiveFilterChips({
            filters,
            options: mockOptions,
            onApplyFilters: () => {},
        });

        assert.equal(chips.length, 1);
        assert.equal(chips[0].key, 'department_ids');
        assert.equal(chips[0].label, 'Departments: Office, Finance');
        assert.equal(
            chips[0].ariaLabel,
            'Remove Departments: Office, Finance filter',
        );
    });

    it('resolves Position filter label', () => {
        const filters: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            position_id: '4',
        };
        const chips = buildEmployeeActiveFilterChips({
            filters,
            options: mockOptions,
            onApplyFilters: () => {},
        });

        assert.equal(chips.length, 1);
        assert.equal(chips[0].key, 'position_id');
        assert.equal(chips[0].label, 'Position: Accountant');
        assert.equal(chips[0].ariaLabel, 'Remove Position: Accountant filter');
    });

    it('shows both Department and Position when both are selected', () => {
        const filters: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            department_id: '11',
            position_id: '4',
        };
        const chips = buildEmployeeActiveFilterChips({
            filters,
            options: mockOptions,
            onApplyFilters: () => {},
        });

        assert.equal(chips.length, 2);
        assert.equal(chips[0].label, 'Department: Finance');
        assert.equal(chips[1].label, 'Position: Accountant');
    });

    it('omits HR Status chip when status is blank (default active)', () => {
        const filters: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            status: '',
        };
        const chips = buildEmployeeActiveFilterChips({
            filters,
            options: mockOptions,
            onApplyFilters: () => {},
        });

        assert.equal(chips.length, 0);
    });

    it('resolves HR Status chips when explicitly selected', () => {
        const statuses = [
            { value: 'inactive', label: 'HR Status: Inactive' },
            { value: 'on_leave', label: 'HR Status: On leave' },
            { value: 'terminated', label: 'HR Status: Terminated' },
            { value: 'active', label: 'HR Status: Active' },
            { value: 'all', label: 'HR Status: All statuses' },
        ];

        for (const { value, label } of statuses) {
            const filters: EmployeeFilters = {
                ...EMPTY_EMPLOYEE_FILTERS,
                status: value,
            };
            const chips = buildEmployeeActiveFilterChips({
                filters,
                options: mockOptions,
                onApplyFilters: () => {},
            });

            assert.equal(chips.length, 1);
            assert.equal(chips[0].key, 'status');
            assert.equal(chips[0].label, label);
            assert.equal(chips[0].ariaLabel, `Remove ${label} filter`);
        }
    });

    it('resolves Client and Project filter labels', () => {
        const filters: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            client_id: '3',
            project_id: '8',
        };
        const chips = buildEmployeeActiveFilterChips({
            filters,
            options: mockOptions,
            onApplyFilters: () => {},
        });

        assert.equal(chips.length, 2);
        assert.equal(chips[0].label, 'Client: ADNOC');
        assert.equal(chips[1].label, 'Project: Project A');
    });

    it('resolves Manager, Role, Nationality, Gender, Visa Type, Sponsor', () => {
        const filters: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            manager_id: '1',
            role_id: '2',
            nationality_id: '101',
            gender_id: '1',
            visa_type_id: '2',
            company_visa_type_id: '3',
        };
        const chips = buildEmployeeActiveFilterChips({
            filters,
            options: mockOptions,
            onApplyFilters: () => {},
        });

        const labels = chips.map((c) => c.label);
        assert.deepEqual(labels, [
            'Manager: Mohammed Rabil',
            'Role: HR Manager',
            'Nationality: India',
            'Gender: Male',
            'Visa Type: Employment',
            'Sponsor: OMS',
        ]);
    });

    it('splits multi-value Approval Location CSV into separate chips', () => {
        const filters: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            approval_location_id: '1,3',
        };
        const chips = buildEmployeeActiveFilterChips({
            filters,
            options: mockOptions,
            onApplyFilters: () => {},
        });

        assert.equal(chips.length, 2);
        assert.equal(chips[0].key, 'approval_location_id:1');
        assert.equal(chips[0].label, 'Approval Location: Abu Dhabi');
        assert.equal(
            chips[0].ariaLabel,
            'Remove Approval Location: Abu Dhabi filter',
        );

        assert.equal(chips[1].key, 'approval_location_id:3');
        assert.equal(chips[1].label, 'Approval Location: Dubai');
        assert.equal(
            chips[1].ariaLabel,
            'Remove Approval Location: Dubai filter',
        );
    });

    it('splits multi-value SSSA CSV into separate chips', () => {
        const filters: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            sssa_option_id: '2,4',
        };
        const chips = buildEmployeeActiveFilterChips({
            filters,
            options: mockOptions,
            onApplyFilters: () => {},
        });

        assert.equal(chips.length, 2);
        assert.equal(chips[0].key, 'sssa_option_id:2');
        assert.equal(chips[0].label, 'SSSA: Option A');
        assert.equal(chips[1].key, 'sssa_option_id:4');
        assert.equal(chips[1].label, 'SSSA: Option C');
    });

    it('integrates completeness filters into unified output', () => {
        const filters: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            nationality_id: '101',
            missing_fields: 'emirates_id',
            present_fields: 'passport_number',
        };
        const chips = buildEmployeeActiveFilterChips({
            filters,
            options: mockOptions,
            onApplyFilters: () => {},
        });

        const labels = chips.map((c) => c.label);
        assert.deepEqual(labels, [
            'Nationality: India',
            'Missing · Emirates ID',
            'Present · Passport',
        ]);
    });

    it('falls back to Unknown for unresolvable IDs without throwing', () => {
        const filters: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            department_id: '999',
            position_id: '999',
            manager_id: '999',
            role_id: '999',
            client_id: '999',
            project_id: '999',
            nationality_id: '999',
            gender_id: '999',
            visa_type_id: '999',
            company_visa_type_id: '999',
            approval_location_id: '999',
            sssa_option_id: '999',
        };

        const chips = buildEmployeeActiveFilterChips({
            filters,
            options: {},
            onApplyFilters: () => {},
        });

        for (const chip of chips) {
            assert.match(chip.label, /Unknown/);
        }
    });

    it('shows Search chip when search is non-empty and provides onClearSearch', () => {
        let cleared = false;
        const chips = buildEmployeeActiveFilterChips({
            filters: EMPTY_EMPLOYEE_FILTERS,
            searchInput: 'Siyad',
            options: mockOptions,
            onClearSearch: () => {
                cleared = true;
            },
            onApplyFilters: () => {},
        });

        assert.equal(chips.length, 1);
        assert.equal(chips[0].key, 'search');
        assert.equal(chips[0].label, 'Search: Siyad');
        assert.equal(chips[0].ariaLabel, 'Remove Search: Siyad filter');

        chips[0].onClear();
        assert.equal(cleared, true);
    });

    it('never treats per_page as an active filter', () => {
        // EmployeeFilters does not have per_page, but verify passing extra properties
        const filtersWithPerPage = {
            ...EMPTY_EMPLOYEE_FILTERS,
            per_page: 20,
        };
        const chips = buildEmployeeActiveFilterChips({
            filters: filtersWithPerPage as unknown as EmployeeFilters,
            options: mockOptions,
            onApplyFilters: () => {},
        });

        assert.equal(chips.length, 0);
    });
});

describe('employee active filters - clearing behavior', () => {
    it('clearing Department also clears Position', () => {
        const initial: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            department_id: '10',
            position_id: '4',
        };

        const result = clearDepartmentFilter(initial);

        assert.equal(result.department_id, '');
        assert.equal(result.position_id, '');
    });

    it('clearing Position preserves Department', () => {
        const initial: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            department_id: '10',
            position_id: '4',
        };

        const result = clearPositionFilter(initial);

        assert.equal(result.department_id, '10');
        assert.equal(result.position_id, '');
    });

    it('clearing one Approval Location preserves other IDs', () => {
        const initial: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            approval_location_id: '1,3',
        };

        const afterRemoving1 = clearApprovalLocationFilter(initial, '1');
        assert.equal(afterRemoving1.approval_location_id, '3');

        const afterRemoving3 = clearApprovalLocationFilter(afterRemoving1, '3');
        assert.equal(afterRemoving3.approval_location_id, '');
    });

    it('clearing one SSSA value preserves other IDs', () => {
        const initial: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            sssa_option_id: '2,4',
        };

        const afterRemoving2 = clearSssaOptionFilter(initial, '2');
        assert.equal(afterRemoving2.sssa_option_id, '4');

        const afterRemoving4 = clearSssaOptionFilter(afterRemoving2, '4');
        assert.equal(afterRemoving4.sssa_option_id, '');
    });

    it('clearing Client preserves Project when valid in full project list', () => {
        const initial: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            client_id: '3',
            project_id: '8', // Project A
        };

        const result = clearClientFilter(initial, mockProjects);

        assert.equal(result.client_id, '');
        assert.equal(result.project_id, '8');
    });

    it('clearing completeness removes only the targeted concept', () => {
        let appliedFilters: EmployeeFilters | null = null;
        const initial: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            missing_fields: 'emirates_id,passport_number',
        };

        const chips = buildEmployeeActiveFilterChips({
            filters: initial,
            options: mockOptions,
            onApplyFilters: (next) => {
                appliedFilters = next;
            },
        });

        assert.equal(chips.length, 2);
        assert.equal(chips[0].label, 'Missing · Emirates ID');

        // Click X on Emirates ID
        chips[0].onClear();
        assert.notEqual(appliedFilters, null);
        assert.equal(
            (appliedFilters as unknown as EmployeeFilters).missing_fields,
            'passport_number',
        );
    });

    it('clicking onClear on Department chip invokes callback with both Department and Position cleared', () => {
        let appliedFilters: EmployeeFilters | null = null;
        const initial: EmployeeFilters = {
            ...EMPTY_EMPLOYEE_FILTERS,
            department_id: '10',
            position_id: '4',
        };

        const chips = buildEmployeeActiveFilterChips({
            filters: initial,
            options: mockOptions,
            onApplyFilters: (next) => {
                appliedFilters = next;
            },
        });

        const deptChip = chips.find((c) => c.key === 'department_ids');
        assert.notEqual(deptChip, undefined);

        deptChip?.onClear();
        assert.notEqual(appliedFilters, null);
        assert.equal(
            (appliedFilters as unknown as EmployeeFilters).department_id,
            '',
        );
        assert.equal(
            (appliedFilters as unknown as EmployeeFilters).department_ids,
            '',
        );
        assert.equal(
            (appliedFilters as unknown as EmployeeFilters).position_id,
            '',
        );
    });
});

describe('employee active filters - hasActiveEmployeeFilters', () => {
    it('returns false for empty filters without search', () => {
        assert.equal(hasActiveEmployeeFilters(EMPTY_EMPLOYEE_FILTERS), false);
        assert.equal(
            hasActiveEmployeeFilters(EMPTY_EMPLOYEE_FILTERS, ''),
            false,
        );
    });

    it('returns true when search is present', () => {
        assert.equal(
            hasActiveEmployeeFilters(EMPTY_EMPLOYEE_FILTERS, 'Siyad'),
            true,
        );
    });

    it('returns true when any filter is set', () => {
        assert.equal(
            hasActiveEmployeeFilters({
                ...EMPTY_EMPLOYEE_FILTERS,
                department_id: '10',
            }),
            true,
        );
        assert.equal(
            hasActiveEmployeeFilters({
                ...EMPTY_EMPLOYEE_FILTERS,
                department_ids: '10,11',
            }),
            true,
        );
        assert.equal(
            hasActiveEmployeeFilters({
                ...EMPTY_EMPLOYEE_FILTERS,
                status: 'inactive',
            }),
            true,
        );
        assert.equal(
            hasActiveEmployeeFilters({
                ...EMPTY_EMPLOYEE_FILTERS,
                missing_fields: 'emirates_id',
            }),
            true,
        );
    });

    it('returns false when status is default blank', () => {
        assert.equal(
            hasActiveEmployeeFilters({
                ...EMPTY_EMPLOYEE_FILTERS,
                status: '',
            }),
            false,
        );
    });
});
