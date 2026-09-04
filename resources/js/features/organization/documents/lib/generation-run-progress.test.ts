import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    generationCompletionToast,
    generationHasIssues,
    isGenerationRunActive,
    rosterGenerationBadge,
    shouldPollBulkDocuments,
} from './generation-run-progress.ts';

describe('generation-run-progress', () => {
    it('treats queued and running as active', () => {
        assert.equal(isGenerationRunActive('queued'), true);
        assert.equal(isGenerationRunActive('running'), true);
        assert.equal(isGenerationRunActive('completed'), false);
        assert.equal(isGenerationRunActive('failed'), false);
    });

    it('polls while generation or email is active and stops otherwise', () => {
        assert.equal(shouldPollBulkDocuments(true, false), true);
        assert.equal(shouldPollBulkDocuments(false, true), true);
        assert.equal(shouldPollBulkDocuments(true, true), true);
        assert.equal(shouldPollBulkDocuments(false, false), false);
    });

    it('treats completed runs with failures as issues', () => {
        assert.equal(
            generationHasIssues({ status: 'completed', failed_count: 1 }),
            true,
        );
        assert.equal(
            generationHasIssues({ status: 'completed', failed_count: 0 }),
            false,
        );
        assert.equal(
            generationHasIssues({ status: 'failed', failed_count: 1 }),
            false,
        );
    });

    it('builds a one-shot completion toast payload', () => {
        const success = generationCompletionToast({
            status: 'completed',
            template_name: 'Salary Declaration',
            generated_count: 20,
            skipped_count: 0,
            failed_count: 0,
            total_targeted: 20,
        });
        assert.equal(success.type, 'success');
        assert.equal(success.title, 'Document generation completed');

        const issues = generationCompletionToast({
            status: 'completed',
            template_name: 'Salary Declaration',
            generated_count: 18,
            skipped_count: 1,
            failed_count: 1,
            total_targeted: 20,
        });
        assert.equal(issues.type, 'warning');
        assert.match(issues.body, /18 generated/);

        const failed = generationCompletionToast({
            status: 'failed',
            template_name: 'Salary Declaration',
            generated_count: 0,
            skipped_count: 0,
            failed_count: 1,
            total_targeted: 20,
        });
        assert.equal(failed.type, 'error');
        assert.equal(failed.title, 'Document generation failed');
    });

    it('maps live run item status onto roster badges without replacing generated documents', () => {
        assert.deepEqual(
            rosterGenerationBadge({
                document: null,
                generation_run_status: 'processing',
            }),
            { kind: 'generating', label: 'Generating' },
        );
        assert.deepEqual(
            rosterGenerationBadge({
                document: null,
                generation_run_status: 'failed',
            }),
            { kind: 'failed', label: 'Failed' },
        );
        assert.deepEqual(
            rosterGenerationBadge({
                document: { id: 9 },
                generation_run_status: 'completed',
            }),
            { kind: 'generated', label: 'Generated' },
        );
        assert.deepEqual(
            rosterGenerationBadge({
                document: null,
                generation_run_status: null,
            }),
            { kind: 'missing', label: 'Missing' },
        );
    });
});
