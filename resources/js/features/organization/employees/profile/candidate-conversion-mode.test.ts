import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type {
    CandidateConversionContext,
    EmployeeTab,
} from '@/pages/organization/employee-page.types';
import {
    CANDIDATE_CONVERSION_UNAVAILABLE_TAB_MESSAGE,
    isCandidateConversionMode,
    isRecordTabUnavailableInConversion,
} from './candidate-conversion-mode.ts';

describe('candidate-conversion-mode guard', () => {
    const validCandidateContext: CandidateConversionContext = {
        candidate_id: 42,
        name: 'Jane Doe',
        email: 'jane@example.com',
        phone: '+1234567890',
        nationality_id: 1,
        nationality_name: 'UAE',
        position_id: 5,
        position_title: 'Chief Officer',
        requirement_number: 'REQ-001',
        client_name: 'ADNOC',
        project_title: 'Offshore Alpha',
        actual_joining_date: '2026-03-01',
        lock_version: 1,
        proposed_offer: null,
        duplicate_matches: [],
        can_link_existing: true,
    };

    it('identifies candidate conversion mode correctly', () => {
        assert.equal(isCandidateConversionMode(validCandidateContext), true);
        assert.equal(isCandidateConversionMode(null), false);
        assert.equal(isCandidateConversionMode(undefined), false);
        assert.equal(
            isCandidateConversionMode({
                ...validCandidateContext,
                candidate_id: 0,
            }),
            false,
        );
        assert.equal(
            isCandidateConversionMode({
                ...validCandidateContext,
                candidate_id: -1,
            }),
            false,
        );
    });

    it('keeps personal tab accessible during candidate conversion', () => {
        assert.equal(
            isRecordTabUnavailableInConversion(
                'personal',
                validCandidateContext,
            ),
            false,
        );
    });

    it('makes every record tab unavailable during candidate conversion', () => {
        const recordTabs: EmployeeTab[] = [
            'contract',
            'salary_revisions',
            'bank',
            'education',
            'work_experience',
            'vaccination',
            'languages',
            'training',
            'sea_service',
            'documents',
        ];

        for (const tab of recordTabs) {
            assert.equal(
                isRecordTabUnavailableInConversion(tab, validCandidateContext),
                true,
                `Expected tab ${tab} to be unavailable during candidate conversion`,
            );
        }
    });

    it('leaves all tabs available when not in candidate conversion mode', () => {
        const allTabs: EmployeeTab[] = [
            'personal',
            'contract',
            'salary_revisions',
            'bank',
            'education',
            'work_experience',
            'vaccination',
            'languages',
            'training',
            'sea_service',
            'documents',
        ];

        for (const tab of allTabs) {
            assert.equal(
                isRecordTabUnavailableInConversion(tab, null),
                false,
                `Expected tab ${tab} to be available outside conversion mode`,
            );
            assert.equal(
                isRecordTabUnavailableInConversion(tab, undefined),
                false,
                `Expected tab ${tab} to be available when context is undefined`,
            );
        }
    });

    it('provides the required user-facing unavailable message', () => {
        assert.equal(
            CANDIDATE_CONVERSION_UNAVAILABLE_TAB_MESSAGE,
            'Create the employee first to add these records.',
        );
    });
});
