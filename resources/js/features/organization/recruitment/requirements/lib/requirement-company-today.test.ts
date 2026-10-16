import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { nowInCompanyDate } from '../../../../../lib/company-timezone.ts';

describe('recruitment request received date defaults', () => {
    it('uses company-local today instead of UTC around Dubai midnight', () => {
        const instant = new Date('2026-10-15T20:30:00.000Z'); // 00:30 Asia/Dubai
        const companyToday = nowInCompanyDate('Asia/Dubai', instant);
        const utcToday = instant.toISOString().split('T')[0];

        assert.equal(companyToday, '2026-10-16');
        assert.equal(utcToday, '2026-10-15');
        assert.notEqual(companyToday, utcToday);
    });

    it('follows a different company timezone than the browser clock', () => {
        const instant = new Date('2026-10-16T02:00:00.000Z');
        assert.equal(
            nowInCompanyDate('America/New_York', instant),
            '2026-10-15',
        );
        assert.equal(nowInCompanyDate('Asia/Dubai', instant), '2026-10-16');
    });
});
