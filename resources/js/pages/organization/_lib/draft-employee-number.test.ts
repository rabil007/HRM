import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    isDraftEmployeeNumber,
    isOfficialEmployeeNumberMissing,
} from './draft-employee-number.ts';

describe('draft employee number helpers', () => {
    it('detects provisional DRAFT identifiers', () => {
        assert.equal(isDraftEmployeeNumber('DRAFT-FEIXOIU9'), true);
        assert.equal(isDraftEmployeeNumber('draft-abcdefgh'), true);
        assert.equal(isDraftEmployeeNumber(' EMP-1001 '), false);
        assert.equal(isDraftEmployeeNumber(''), false);
        assert.equal(isDraftEmployeeNumber(null), false);
    });

    it('treats blank and draft values as missing official numbers', () => {
        assert.equal(isOfficialEmployeeNumberMissing(''), true);
        assert.equal(isOfficialEmployeeNumberMissing('   '), true);
        assert.equal(isOfficialEmployeeNumberMissing('DRAFT-ABCD1234'), true);
        assert.equal(isOfficialEmployeeNumberMissing('EMP-1001'), false);
    });
});
