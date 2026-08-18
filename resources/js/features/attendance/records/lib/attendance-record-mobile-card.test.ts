import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { AttendanceRecord } from '../types.ts';
import { attendanceRecordMobileCardModel } from './attendance-record-mobile-card.ts';

function record(overrides: Partial<AttendanceRecord> = {}): AttendanceRecord {
    return {
        id: 3,
        employee: { id: 12, employee_no: 'EMP-0012', name: 'Mohammed Rabil' },
        date: '2026-08-18',
        clock_in: '2026-08-18T08:00:00',
        clock_out: '2026-08-18T17:00:00',
        hours_worked: '8',
        overtime_hours: '0',
        late_minutes: 0,
        source: 'hikvision',
        status: 'present',
        notes: null,
        ...overrides,
    };
}

describe('attendanceRecordMobileCardModel', () => {
    it('omits employee identity for self-service users', () => {
        const model = attendanceRecordMobileCardModel(record(), {
            update: false,
            delete: false,
            manage: false,
        });

        assert.equal(model.showEmployeeIdentity, false);
        assert.equal(model.title, '2026-08-18');
        assert.equal(model.subtitle, null);
        assert.equal(model.showEdit, false);
        assert.equal(model.showDelete, false);
    });

    it('shows employee identity for attendance managers', () => {
        const model = attendanceRecordMobileCardModel(record(), {
            update: true,
            delete: true,
            manage: true,
        });

        assert.equal(model.showEmployeeIdentity, true);
        assert.equal(model.title, 'Mohammed Rabil');
        assert.equal(model.subtitle, '2026-08-18');
        assert.equal(model.showEdit, true);
        assert.equal(model.showDelete, true);
    });

    it('keeps manage-only mutations gated', () => {
        const selfService = attendanceRecordMobileCardModel(record(), {
            update: false,
            delete: false,
            manage: false,
        });

        assert.equal(selfService.showEdit, false);
        assert.equal(selfService.showDelete, false);
    });

    it('flags late and missing check-out anomalies', () => {
        const late = attendanceRecordMobileCardModel(
            record({ late_minutes: 12, status: 'late' }),
            { update: false, delete: false, manage: false },
        );
        const open = attendanceRecordMobileCardModel(
            record({ clock_out: null }),
            { update: false, delete: false, manage: false },
        );

        assert.equal(late.attention, 'Late 12 min');
        assert.equal(open.attention, 'Missing check-out');
    });
});
