import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    attendanceHref,
    canOpenApplicationSettings,
    canViewCrewOperations,
    canViewPayroll,
    crewOperationsHref,
    hasSettingsAccess,
    isSidebarUrlVisible,
    NO_PLATFORM_ACCESS,
    payrollHref,
    visibleGroupUrls,
} from '../../../lib/nav-visibility.ts';

const USERS_URL = '/organization/users';
const EMPLOYEES_URL = '/organization/employees';
const CREW_URLS = [
    '/organization/crew-operations',
    '/organization/crew',
    '/organization/crew-planning',
    '/organization/vessels',
    '/organization/vessel-manning',
    '/organization/crew-operations/settings',
    '/organization/crew-movement-corrections',
];
const PAYROLL_URLS = [
    '/payroll/overview',
    '/payroll',
    '/payroll/records',
    '/payroll/salary-inputs',
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
            '/organization/vessel-manning',
        );
        assert.equal(
            isSidebarUrlVisible('/organization/vessels', permissions),
            false,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/vessel-manning', permissions),
            true,
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
});

describe('Attendance top-nav landing', () => {
    it('uses records when the user can view records', () => {
        assert.equal(
            attendanceHref(['attendance.records.view']),
            '/attendance/records',
        );
    });

    it('uses overview when the user can only view overview', () => {
        assert.equal(
            attendanceHref(['attendance.overview.view']),
            '/attendance/overview',
        );
    });

    it('hides attendance when neither overview nor records is granted', () => {
        assert.equal(attendanceHref([]), null);
    });
});

describe('Settings navigation', () => {
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
        '/organization/documents/activity',
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

    it('shows generate templates and activity for bulk_documents.view', () => {
        assert.deepEqual(
            visibleGroupUrls(documentsUrls, ['bulk_documents.view']),
            [
                '/organization/documents/templates',
                '/organization/documents/generate',
                '/organization/documents/activity',
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

    it('shows templates for document types or platform access', () => {
        assert.equal(
            isSidebarUrlVisible('/organization/documents/templates', [
                'settings.master-data.document-types.view',
            ]),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/documents/templates', [], {
                view: true,
                manage: false,
                database: false,
            }),
            true,
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
            ['/organization/crew'],
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
