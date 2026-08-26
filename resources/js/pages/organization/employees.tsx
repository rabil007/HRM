import { Head } from '@inertiajs/react';
import { EmployeesContent } from '@/features/organization/employees';
import type {
    BankOption,
    CompanyVisaTypeOption,
    CountryOption,
    DepartmentTreeNode,
    Employee,
    EmployeeExportFieldOption,
    EmployeePageCan,
    GenderOption,
    ApprovalLocationOption,
    ManagerOption,
    PositionOption,
    RankOption,
    ReligionOption,
    RoleOption,
    SssaOption,
    UserOption,
    VisaTypeOption,
} from '@/features/organization/employees/types';
import type { SavedView } from '@/lib/saved-views';
import type { PaginationMeta } from '@/types/pagination';

export default function Employees({
    employees,
    pagination,
    search,
    filters,
    department_tree,
    department_tree_selected_id,
    department_tree_selected_position_id,
    positions,
    managers,
    users,
    countries,
    religions,
    genders,
    visa_types,
    company_visa_types,
    approval_locations,
    sssa_options,
    ranks,
    banks,
    roles,
    export_field_options,
    can,
    saved_views = [],
    smart_search_enabled = false,
}: {
    employees: Employee[];
    pagination: PaginationMeta;
    search: string;
    filters: {
        department_id: string;
        position_id: string;
        status: string;
        manager_id: string;
        gender_id: string;
        nationality_id: string;
        visa_type_id: string;
        company_visa_type_id: string;
        rank_id: string;
        approval_location_id: string;
        sssa_option_id: string;
        crew_status: string;
        role_id: string;
        emirates_id_presence: string;
    };
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    department_tree_selected_position_id: number | null;
    positions: PositionOption[];
    managers: ManagerOption[];
    users: UserOption[];
    countries: CountryOption[];
    religions: ReligionOption[];
    genders: GenderOption[];
    visa_types: VisaTypeOption[];
    company_visa_types: CompanyVisaTypeOption[];
    approval_locations: ApprovalLocationOption[];
    sssa_options: SssaOption[];
    ranks: RankOption[];
    banks: BankOption[];
    roles: RoleOption[];
    export_field_options: EmployeeExportFieldOption[];
    can: EmployeePageCan;
    saved_views?: SavedView[];
    smart_search_enabled?: boolean;
}) {
    return (
        <>
            <Head title="Employees" />
            <EmployeesContent
                employees={employees}
                pagination={pagination}
                search={search}
                filters={filters}
                department_tree={department_tree}
                department_tree_selected_id={department_tree_selected_id}
                department_tree_selected_position_id={
                    department_tree_selected_position_id
                }
                positions={positions}
                managers={managers}
                users={users}
                countries={countries}
                religions={religions}
                genders={genders}
                visa_types={visa_types}
                company_visa_types={company_visa_types}
                approval_locations={approval_locations}
                sssa_options={sssa_options}
                ranks={ranks}
                banks={banks}
                roles={roles}
                export_field_options={export_field_options}
                can={can}
                saved_views={saved_views}
                smart_search_enabled={smart_search_enabled}
            />
        </>
    );
}
