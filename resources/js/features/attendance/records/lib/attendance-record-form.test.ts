import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { nowInCompanyDate } from '../../../../lib/company-timezone.ts';
import {
    attendanceRecordToFormData,
    defaultAttendanceRecordFormData,
} from '../types.ts';
import type { AttendanceRecord } from '../types.ts';

describe('attendance record form defaults', () => {
    it('uses the provided company-local today for new records', () => {
        const form = defaultAttendanceRecordFormData(12, '2026-10-16');

        assert.equal(form.employee_id, '12');
        assert.equal(form.date, '2026-10-16');
    });

    it('defaults Dubai early morning to the company calendar day', () => {
        // 16 Oct 2026 00:30 Asia/Dubai == 15 Oct 2026 20:30 UTC
        const instant = new Date('2026-10-15T20:30:00.000Z');
        const companyToday = nowInCompanyDate('Asia/Dubai', instant);
        const utcToday = instant.toISOString().slice(0, 10);
        const form = defaultAttendanceRecordFormData(null, companyToday);

        assert.equal(companyToday, '2026-10-16');
        assert.notEqual(companyToday, utcToday);
        assert.equal(form.date, '2026-10-16');
    });

    it('preserves an existing attendance record date on edit', () => {
        const record = {
            id: 1,
            employee: { id: 9, employee_no: 'E9', name: 'Ada' },
            date: '2026-09-01',
            clock_in: null,
            clock_out: null,
            hours_worked: null,
            overtime_hours: null,
            late_minutes: 0,
            source: 'manual',
            status: 'present',
            notes: null,
        } satisfies AttendanceRecord;

        assert.equal(attendanceRecordToFormData(record).date, '2026-09-01');
    });
});
