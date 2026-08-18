import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { Employee } from '../types.ts';
import {
    employeeMobileCardModel,
    employeeMobileCardOmitsSensitiveFields,
} from './employee-mobile-card.ts';

function employee(overrides: Partial<Employee> = {}): Employee {
    return {
        id: 12,
        user_id: null,
        branch_id: 1,
        department_id: 2,
        position_id: 3,
        employee_no: 'EMP-0012',
        name: 'Mohammed Rabil',
        branch: { id: 1, name: 'Main' },
        department: { id: 2, name: 'Marine' },
        position: { id: 3, title: 'Engineer' },
        work_email: 'rabil@example.com',
        personal_email: 'private@example.com',
        phone: '0500000000',
        phone_home_country: '00968',
        emergency_contact: 'Spouse',
        emergency_phone: '0501111111',
        date_of_birth: '1990-01-01',
        spouse_name: 'Hidden',
        passport_number: 'P123',
        emirates_id: '784-000',
        basic_salary: 10000,
        status: 'active',
        created_at: '2026-01-01T00:00:00Z',
        ...overrides,
    };
}

describe('employeeMobileCardModel', () => {
    it('derives identity, assignment, and status for the compact card', () => {
        const model = employeeMobileCardModel(employee(), {
            update: false,
            delete: false,
        });

        assert.equal(model.title, 'Mohammed Rabil');
        assert.equal(model.subtitle, 'EMP-0012');
        assert.equal(model.assignmentLine, 'Marine · Engineer');
        assert.equal(model.status, 'active');
        assert.equal(model.showEdit, false);
        assert.equal(model.showDelete, false);
    });

    it('does not put sensitive fields on the card model', () => {
        const model = employeeMobileCardModel(employee(), {
            update: true,
            delete: true,
        });

        assert.equal(employeeMobileCardOmitsSensitiveFields(model), true);
        assert.equal(
            JSON.stringify(model).includes('rabil@example.com'),
            false,
        );
        assert.equal(JSON.stringify(model).includes('0500000000'), false);
        assert.equal(JSON.stringify(model).includes('10000'), false);
        assert.equal(JSON.stringify(model).includes('P123'), false);
    });

    it('gates mutation actions by permission', () => {
        const viewOnly = employeeMobileCardModel(employee(), {
            update: false,
            delete: false,
        });
        const updater = employeeMobileCardModel(employee(), {
            update: true,
            delete: false,
        });
        const deleter = employeeMobileCardModel(employee(), {
            update: false,
            delete: true,
        });

        assert.equal(viewOnly.showEdit, false);
        assert.equal(viewOnly.showDelete, false);
        assert.equal(updater.showEdit, true);
        assert.equal(updater.showDelete, false);
        assert.equal(deleter.showDelete, true);
    });

    it('surfaces an existing crew attention signal', () => {
        const model = employeeMobileCardModel(
            employee({
                crew_status: {
                    deployment_id: 4,
                    status: 'on_vessel',
                    label: 'Onboard',
                    warning: 'Sign-off in 3 days',
                    current_vessel: 'Horizon',
                    vessel_name: 'Horizon',
                },
            }),
            { update: false, delete: false },
        );

        assert.equal(model.attention, 'Sign-off in 3 days');
    });

    it('maps the same server records without local filtering', () => {
        const records = [
            employee({ id: 1, name: 'A', employee_no: 'E1' }),
            employee({ id: 2, name: 'B', employee_no: 'E2' }),
        ];

        assert.equal(
            records.map((record) =>
                employeeMobileCardModel(record, {
                    update: false,
                    delete: false,
                }),
            ).length,
            records.length,
        );
    });
});
