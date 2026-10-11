import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { getActiveRecruitmentSubmodules } from '../../../features/organization/recruitment/recruitment-nav.ts';
import {
    attendanceHref,
    canOpenApplicationSettings,
    canViewCrewOperations,
    canViewPayroll,
    canViewRecruitment,
    crewOperationsHref,
    hasSettingsAccess,
    hasMasterDataAccess,
    isSidebarUrlVisible,
    NO_PLATFORM_ACCESS,
    payrollHref,
    recruitmentHref,
    visibleGroupUrls,
} from '../../../lib/nav-visibility.ts';

const USERS_URL = '/organization/users';
const EMPLOYEES_URL = '/organization/employees';
const CREW_URLS = [
    '/organization/crew-operations',
    '/organization/crew',
    '/organization/crew-planning',
    '/organization/crew-operations/relief-desk',
    '/organization/crew-operations/readiness',
    '/organization/vessels',
    '/organization/crew-operations/settings',
    '/organization/crew-movement-corrections',
];
const PAYROLL_URLS = [
    '/payroll/overview',
    '/payroll',
    '/payroll/records',
    '/payroll/salary-inputs',
];
const ATTENDANCE_URLS = [
    '/attendance/calendar',
    '/attendance/my-leave',
    '/attendance/leave-approvals',
    '/attendance/records',
    '/attendance/types',
    '/attendance/leave-approval-policies',
];
const PLATFORM_URLS = ['/log', '/jobs', '/mysql'];

describe('Users navigation', () => {
    it('shows Users when the user only has users.view', () => {
        assert.equal(isSidebarUrlVisible(USERS_URL, ['users.view']), true);
    });

    it('hides Users when the user only has users.create', () => {
        assert.equal(isSidebarUrlVisible(USERS_URL, ['users.create']), false);
    });

    it('shows Users when the user has view and create', () => {
        assert.equal(
            isSidebarUrlVisible(USERS_URL, ['users.view', 'users.create']),
            true,
        );
    });

    it('hides Users when the user has neither view nor create', () => {
        assert.equal(isSidebarUrlVisible(USERS_URL, []), false);
    });
});

describe('Employees navigation', () => {
    it('shows the Employees module for viewers', () => {
        assert.equal(
            isSidebarUrlVisible(EMPLOYEES_URL, ['employees.view']),
            true,
        );
    });

    it('does not use create for Employees discoverability', () => {
        assert.equal(
            isSidebarUrlVisible(EMPLOYEES_URL, ['employees.create']),
            false,
        );
    });
});

describe('Payroll navigation', () => {
    it('lands overview-only users on the overview page', () => {
        const permissions = ['payroll.overview.view'];

        assert.equal(canViewPayroll(permissions), true);
        assert.equal(payrollHref(permissions), '/payroll/overview');
        assert.equal(
            isSidebarUrlVisible('/payroll/overview', permissions),
            true,
        );
        assert.equal(isSidebarUrlVisible('/payroll', permissions), false);
        assert.equal(
            isSidebarUrlVisible('/payroll/salary-inputs', permissions),
            false,
        );
    });

    it('lands period viewers on the payroll hub', () => {
        assert.equal(payrollHref(['payroll.periods.view']), '/payroll');
    });

    it('hides payroll destinations without a payroll capability', () => {
        assert.equal(canViewPayroll([]), false);
        assert.deepEqual(visibleGroupUrls(PAYROLL_URLS, []), []);
    });

    it('does not expose salary inputs for create-without-view', () => {
        assert.equal(
            isSidebarUrlVisible('/payroll/salary-inputs', [
                'payroll.salary_inputs.create',
            ]),
            false,
        );
    });

    it('shows salary inputs for periods.update without salary_inputs.view', () => {
        assert.equal(
            isSidebarUrlVisible('/payroll/salary-inputs', [
                'payroll.periods.update',
            ]),
            true,
        );
        assert.equal(
            payrollHref(['payroll.periods.update']),
            '/payroll/salary-inputs',
        );
    });
});

describe('Crew navigation', () => {
    it('shows crew destinations from view permissions, not mutations', () => {
        assert.equal(
            canViewCrewOperations(['crew_operations.overview.view']),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/crew-operations', [
                'crew_operations.overview.view',
            ]),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/crew-planning', [
                'crew_operations.planning.create',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/crew-operations/relief-desk', [
                'crew_operations.planning.view',
            ]),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/crew-operations/relief-desk', [
                'crew_operations.assignments.view',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/crew-operations/readiness', [
                'crew_operations.planning.view',
            ]),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/crew-operations/readiness', [
                'crew_operations.assignments.view',
            ]),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/crew-operations/readiness', [
                'crew_operations.settings.view',
            ]),
            false,
        );
    });

    it('lands assignment-only users on assignments, not vessels', () => {
        const permissions = ['crew_operations.assignments.view'];

        assert.equal(canViewCrewOperations(permissions), true);
        assert.equal(crewOperationsHref(permissions), '/organization/crew');
        assert.equal(
            isSidebarUrlVisible('/organization/vessels', permissions),
            false,
        );
    });

    it('does not send manning-only users to the vessels index', () => {
        const permissions = ['crew_operations.vessel_manning.view'];

        assert.equal(canViewCrewOperations(permissions), true);
        assert.equal(
            crewOperationsHref(permissions),
            '/organization/crew-operations',
        );
        assert.equal(
            isSidebarUrlVisible('/organization/vessels', permissions),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/vessel-manning', permissions),
            false,
        );
    });

    it('shows vessels only for vessels.view', () => {
        assert.equal(
            isSidebarUrlVisible('/organization/vessels', [
                'crew_operations.vessels.view',
            ]),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/vessel-manning', [
                'crew_operations.vessels.view',
            ]),
            false,
        );
        assert.equal(
            crewOperationsHref(['crew_operations.vessels.view']),
            '/organization/vessels',
        );
    });

    it('lands corrections-only users on movement corrections', () => {
        assert.equal(
            crewOperationsHref(['crew_operations.corrections.view']),
            '/organization/crew-movement-corrections',
        );
    });

    it('shows settings from settings.view, not planning.view', () => {
        assert.equal(
            isSidebarUrlVisible('/organization/crew-operations/settings', [
                'crew_operations.planning.view',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/crew-operations/settings', [
                'crew_operations.settings.view',
            ]),
            true,
        );
        assert.equal(
            canViewCrewOperations(['crew_operations.settings.view']),
            true,
        );
        assert.equal(
            crewOperationsHref(['crew_operations.settings.view']),
            '/organization/crew-operations/settings',
        );
    });
});

describe('Attendance top-nav landing', () => {
    it('uses records when the user can view records', () => {
        assert.equal(
            attendanceHref(['attendance.records.view']),
            '/attendance/records',
        );
    });

    it('uses calendar when the user can view leave but not records', () => {
        assert.equal(
            attendanceHref(['attendance.leave-requests.view']),
            '/attendance/calendar',
        );
    });

    it('does not land on the removed overview destination', () => {
        assert.equal(attendanceHref(['attendance.overview.view']), null);
        assert.deepEqual(
            visibleGroupUrls(ATTENDANCE_URLS, ['attendance.overview.view']),
            [],
        );
        assert.deepEqual(
            visibleGroupUrls(ATTENDANCE_URLS, ['attendance.records.view']),
            ['/attendance/records'],
        );
    });

    it('hides attendance when no attendance child destination is granted', () => {
        assert.equal(attendanceHref([]), null);
    });
});

describe('Settings navigation', () => {
    it('shows Master Data only for viewers of its resources or platform viewers', () => {
        assert.equal(
            hasMasterDataAccess(['settings.master-data.countries.view']),
            true,
        );
        assert.equal(hasMasterDataAccess(['settings.security.view']), false);
        assert.equal(
            hasMasterDataAccess(['settings.master-data.vessels.view']),
            false,
        );
        assert.equal(
            hasMasterDataAccess([], {
                view: true,
                manage: false,
                database: false,
            }),
            true,
        );
    });
    it('lets platform viewers open Application settings', () => {
        assert.equal(
            canOpenApplicationSettings([], {
                view: true,
                manage: false,
                database: false,
            }),
            true,
        );
        assert.equal(
            hasSettingsAccess([], {
                view: true,
                manage: false,
                database: false,
            }),
            true,
        );
    });

    it('does not allow tenant permissions alone to open Application settings', () => {
        assert.equal(
            canOpenApplicationSettings(['settings.application.view']),
            false,
        );
        assert.equal(
            canOpenApplicationSettings(['settings.integrations.whatsapp.view']),
            false,
        );
    });

    it('lets tenant settings viewers open the settings hub', () => {
        assert.equal(hasSettingsAccess(['settings.security.view']), true);
        assert.equal(
            hasSettingsAccess(['settings.integrations.hikvision.view']),
            true,
        );
        assert.equal(
            hasSettingsAccess(['settings.master-data.countries.view']),
            true,
        );
    });

    it('hides settings access without settings permissions or platform view', () => {
        assert.equal(canOpenApplicationSettings([]), false);
        assert.equal(hasSettingsAccess(['employees.view']), false);
    });
});

describe('Platform navigation', () => {
    it('hides platform tooling from tenant-only users', () => {
        assert.deepEqual(
            visibleGroupUrls(
                PLATFORM_URLS,
                ['companies.view'],
                NO_PLATFORM_ACCESS,
            ),
            [],
        );
    });

    it('shows logs and jobs for platform:view, not the database viewer', () => {
        assert.deepEqual(
            visibleGroupUrls(PLATFORM_URLS, [], {
                view: true,
                manage: false,
                database: false,
            }),
            ['/log', '/jobs'],
        );
    });

    it('uses platform:database for the database viewer', () => {
        assert.equal(
            isSidebarUrlVisible('/mysql', [], {
                view: true,
                manage: false,
                database: true,
            }),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/mysql', [], {
                view: true,
                manage: true,
                database: false,
            }),
            false,
        );
    });

    it('does not use platform:manage for discovery', () => {
        assert.deepEqual(
            visibleGroupUrls(PLATFORM_URLS, [], {
                view: false,
                manage: true,
                database: false,
            }),
            [],
        );
    });
});

describe('Documents navigation', () => {
    const documentsUrls = [
        '/organization/documents',
        '/organization/documents/library',
        '/organization/documents/templates',
        '/organization/documents/generate',
        '/organization/documents/requests',
        '/organization/documents/configuration',
    ];

    it('shows overview and library only for documents.view', () => {
        assert.deepEqual(visibleGroupUrls(documentsUrls, ['documents.view']), [
            '/organization/documents',
            '/organization/documents/library',
        ]);
        assert.equal(
            isSidebarUrlVisible('/organization/documents/generate', [
                'documents.view',
            ]),
            false,
        );
    });

    it('shows templates and Generate & Track for bulk_documents.view', () => {
        assert.deepEqual(
            visibleGroupUrls(documentsUrls, ['bulk_documents.view']),
            [
                '/organization/documents/templates',
                '/organization/documents/generate',
            ],
        );
        assert.equal(
            isSidebarUrlVisible('/organization/documents/configuration', [
                'bulk_documents.view',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/documents', [
                'bulk_documents.view',
            ]),
            false,
        );
    });

    it('shows configuration only with document-types.view', () => {
        assert.equal(
            isSidebarUrlVisible('/organization/documents/configuration', [
                'settings.master-data.document-types.view',
            ]),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/documents/configuration', [
                'documents.view',
            ]),
            false,
        );
    });

    it('shows notification routing only with notification-routing.view', () => {
        assert.equal(
            isSidebarUrlVisible(
                '/organization/documents/configuration/notification-routing',
                ['documents.notification-routing.view'],
            ),
            true,
        );
        assert.equal(
            isSidebarUrlVisible(
                '/organization/documents/configuration/notification-routing',
                ['settings.master-data.document-types.view'],
            ),
            false,
        );
    });

    it('shows templates only for bulk or custom template view, not document-types or platform access', () => {
        // Document Types permission no longer exposes Templates — only Configuration
        assert.equal(
            isSidebarUrlVisible('/organization/documents/templates', [
                'settings.master-data.document-types.view',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/documents/templates', [
                'documents.templates.view',
            ]),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/documents/templates', [
                'bulk_documents.view',
            ]),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/documents/templates', [], {
                view: true,
                manage: false,
                database: false,
            }),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/documents/templates', [
                'documents.view',
            ]),
            false,
        );
    });

    it('does not treat the legacy bulk URL as a Documents sidebar destination', () => {
        assert.equal(
            documentsUrls.includes('/organization/documents/bulk'),
            false,
        );
    });
});

describe('Parent groups', () => {
    it('hides a group when every child is inaccessible', () => {
        assert.deepEqual(visibleGroupUrls(CREW_URLS, ['employees.view']), []);
    });

    it('keeps a group when any child is accessible', () => {
        assert.deepEqual(
            visibleGroupUrls(CREW_URLS, ['crew_operations.assignments.view']),
            ['/organization/crew', '/organization/crew-operations/readiness'],
        );
    });
});

describe('Report navigation permissions', () => {
    it('gates Leave Report by reports.leave.view, not attendance permissions', () => {
        assert.equal(
            isSidebarUrlVisible('/organization/reports/leave', [
                'attendance.overview.view',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/reports/leave', [
                'reports.leave.view',
            ]),
            true,
        );
    });

    it('gates Leave Balance Report by reports.leave_balance.view', () => {
        assert.equal(
            isSidebarUrlVisible('/organization/reports/leave-balances', [
                'attendance.leave-requests.view',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/reports/leave-balances', [
                'reports.leave_balance.view',
            ]),
            true,
        );
    });

    it('gates Crew Movement History by reports.crew_movement_history.view', () => {
        assert.equal(
            isSidebarUrlVisible('/organization/reports/crew-movement-history', [
                'crew_operations.overview.view',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/reports/crew-movement-history', [
                'reports.crew_movement_history.view',
            ]),
            true,
        );
    });

    it('gates Relief Report by reports.crew_relief.view', () => {
        assert.equal(
            isSidebarUrlVisible('/organization/reports/crew-relief', [
                'crew_operations.overview.view',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/reports/crew-relief', [
                'reports.crew_relief.view',
            ]),
            true,
        );
    });

    it('gates Hotel Stays by reports.hotel_checkin_checkout.view', () => {
        assert.equal(
            isSidebarUrlVisible(
                '/organization/reports/hotel-checkin-checkout',
                ['crew_operations.overview.view'],
            ),
            false,
        );
        assert.equal(
            isSidebarUrlVisible(
                '/organization/reports/hotel-checkin-checkout',
                ['reports.hotel_checkin_checkout.view'],
            ),
            true,
        );
    });
});

describe('Command palette and company switch', () => {
    it('uses the same destination visibility as the sidebar', () => {
        const permissions = ['users.view', 'employees.view'];

        assert.equal(isSidebarUrlVisible(USERS_URL, permissions), true);
        assert.equal(isSidebarUrlVisible(EMPLOYEES_URL, permissions), true);
        assert.equal(isSidebarUrlVisible('/payroll', permissions), false);
    });

    it('changes visible destinations when auth permissions change', () => {
        const companyA = ['users.view'];
        const companyB = ['payroll.overview.view'];

        assert.equal(isSidebarUrlVisible(USERS_URL, companyA), true);
        assert.equal(isSidebarUrlVisible('/payroll/overview', companyA), false);

        assert.equal(isSidebarUrlVisible(USERS_URL, companyB), false);
        assert.equal(isSidebarUrlVisible('/payroll/overview', companyB), true);
        assert.equal(payrollHref(companyB), '/payroll/overview');
    });
});

describe('Recruitment navigation', () => {
    const RECRUITMENT_PARENT_URL = '/organization/recruitment';
    const RECRUITMENT_REQUIREMENTS_URL =
        '/organization/recruitment/requirements';
    const RECRUITMENT_CANDIDATES_URL = '/organization/recruitment/candidates';

    /**
     * Mirrors `getSidebarData()` Recruitment filtering: only URLs registered in
     * `sidebar-data.ts` / active recruitment submodules that pass
     * `isSidebarUrlVisible` appear.
     */
    function recruitmentSidebarTitles(permissions: string[]): string[] {
        return getActiveRecruitmentSubmodules()
            .filter((submodule) =>
                isSidebarUrlVisible(submodule.href, permissions),
            )
            .map((submodule) => submodule.title);
    }

    it('shows Recruitment parent and requirements when user has recruitment.requirements.view', () => {
        assert.equal(
            isSidebarUrlVisible(RECRUITMENT_REQUIREMENTS_URL, [
                'recruitment.requirements.view',
            ]),
            true,
        );
        assert.equal(
            isSidebarUrlVisible(RECRUITMENT_PARENT_URL, [
                'recruitment.requirements.view',
            ]),
            true,
        );
        assert.equal(
            canViewRecruitment(['recruitment.requirements.view']),
            true,
        );
        assert.equal(
            recruitmentHref(['recruitment.requirements.view']),
            RECRUITMENT_REQUIREMENTS_URL,
        );
    });

    it('shows Recruitment parent and candidates when user has recruitment.candidates.view only', () => {
        assert.equal(
            isSidebarUrlVisible(RECRUITMENT_CANDIDATES_URL, [
                'recruitment.candidates.view',
            ]),
            true,
        );
        assert.equal(
            isSidebarUrlVisible(RECRUITMENT_REQUIREMENTS_URL, [
                'recruitment.candidates.view',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible(RECRUITMENT_PARENT_URL, [
                'recruitment.candidates.view',
            ]),
            true,
        );
        assert.equal(canViewRecruitment(['recruitment.candidates.view']), true);
        assert.equal(
            recruitmentHref(['recruitment.candidates.view']),
            RECRUITMENT_CANDIDATES_URL,
        );
    });

    it('hides Recruitment parent when user lacks requirements and candidates view', () => {
        assert.equal(
            isSidebarUrlVisible(RECRUITMENT_REQUIREMENTS_URL, [
                'recruitment.requirements.create',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible(RECRUITMENT_CANDIDATES_URL, [
                'recruitment.candidates.create',
            ]),
            false,
        );
        assert.equal(
            isSidebarUrlVisible(RECRUITMENT_REQUIREMENTS_URL, []),
            false,
        );
        assert.equal(
            isSidebarUrlVisible(RECRUITMENT_PARENT_URL, [
                'recruitment.requirements.create',
            ]),
            false,
        );
        assert.equal(isSidebarUrlVisible(RECRUITMENT_PARENT_URL, []), false);
        assert.equal(
            canViewRecruitment(['recruitment.requirements.create']),
            false,
        );
        assert.equal(canViewRecruitment([]), false);
        assert.equal(
            recruitmentHref(['recruitment.requirements.create']),
            null,
        );
        assert.equal(recruitmentHref([]), null);
    });

    it('filters group URLs retaining requirements only when permitted', () => {
        assert.deepEqual(
            visibleGroupUrls(
                [RECRUITMENT_REQUIREMENTS_URL, RECRUITMENT_CANDIDATES_URL],
                ['recruitment.requirements.view'],
            ),
            [RECRUITMENT_REQUIREMENTS_URL],
        );
        assert.deepEqual(
            visibleGroupUrls(
                [RECRUITMENT_REQUIREMENTS_URL, RECRUITMENT_CANDIDATES_URL],
                ['recruitment.candidates.view'],
            ),
            [RECRUITMENT_CANDIDATES_URL],
        );
        assert.deepEqual(
            visibleGroupUrls(
                [RECRUITMENT_REQUIREMENTS_URL, RECRUITMENT_CANDIDATES_URL],
                ['recruitment.requirements.create'],
            ),
            [],
        );
    });

    it('renders actual sidebar items for candidates-only, requirements-only, both, and neither', () => {
        assert.deepEqual(
            recruitmentSidebarTitles(['recruitment.candidates.view']),
            ['Candidates'],
        );
        assert.deepEqual(
            recruitmentSidebarTitles(['recruitment.requirements.view']),
            ['Requirements'],
        );
        assert.deepEqual(
            recruitmentSidebarTitles([
                'recruitment.requirements.view',
                'recruitment.candidates.view',
            ]),
            ['Requirements', 'Candidates'],
        );
        assert.deepEqual(recruitmentSidebarTitles([]), []);
        assert.deepEqual(
            recruitmentSidebarTitles(['recruitment.candidates.create']),
            [],
        );
    });

    it('exposes only active recruitment submodules in the module catalog', () => {
        const activeSubmodules = getActiveRecruitmentSubmodules();

        assert.equal(activeSubmodules.length, 3);
        assert.equal(activeSubmodules[0].key, 'requirements');
        assert.equal(activeSubmodules[0].href, RECRUITMENT_REQUIREMENTS_URL);
        assert.equal(activeSubmodules[1].key, 'candidates');
        assert.equal(
            activeSubmodules[1].href,
            '/organization/recruitment/candidates',
        );
        assert.equal(activeSubmodules[2].key, 'reports');
        assert.equal(
            activeSubmodules[2].href,
            '/organization/recruitment/reports',
        );
        assert.equal(
            activeSubmodules.some((sub) => !sub.available),
            false,
        );
    });
});
