import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    DESKTOP_OPERATIONAL_TABLE_CLASS,
    MOBILE_OPERATIONAL_LIST_BREAKPOINT,
    MOBILE_OPERATIONAL_LIST_CLASS,
    joinMobileRecordMeta,
    shouldUseMobileOperationalList,
} from './mobile-operational-list.ts';

describe('shouldUseMobileOperationalList', () => {
    it('selects the mobile representation below the md breakpoint', () => {
        assert.equal(shouldUseMobileOperationalList(320), true);
        assert.equal(shouldUseMobileOperationalList(375), true);
        assert.equal(shouldUseMobileOperationalList(390), true);
        assert.equal(shouldUseMobileOperationalList(430), true);
        assert.equal(
            shouldUseMobileOperationalList(
                MOBILE_OPERATIONAL_LIST_BREAKPOINT - 1,
            ),
            true,
        );
    });

    it('keeps the desktop table at tablet and desktop widths', () => {
        assert.equal(
            shouldUseMobileOperationalList(MOBILE_OPERATIONAL_LIST_BREAKPOINT),
            false,
        );
        assert.equal(shouldUseMobileOperationalList(768), false);
        assert.equal(shouldUseMobileOperationalList(1280), false);
    });
});

describe('responsive class pairing', () => {
    it('uses complementary md visibility classes', () => {
        assert.equal(MOBILE_OPERATIONAL_LIST_CLASS, 'md:hidden');
        assert.equal(DESKTOP_OPERATIONAL_TABLE_CLASS, 'hidden md:block');
    });
});

describe('joinMobileRecordMeta', () => {
    it('joins useful fields and drops blanks', () => {
        assert.equal(
            joinMobileRecordMeta(['Marine', 'Engineer', null, '  ', undefined]),
            'Marine · Engineer',
        );
    });
});
