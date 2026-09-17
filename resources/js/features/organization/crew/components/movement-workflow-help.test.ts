import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    movementWorkflowHelpTopicForAction,
    WORKFLOW_HELP,
} from '../lib/movement-workflow-help-content.ts';

describe('movement workflow help', () => {
    it('maps readiness action keys to help topics', () => {
        assert.equal(
            movementWorkflowHelpTopicForAction('transfer_vessel'),
            'transfer',
        );
        assert.equal(movementWorkflowHelpTopicForAction('redeploy'), 'redeploy');
        assert.equal(
            movementWorkflowHelpTopicForAction('plan_future'),
            'plan_future',
        );
        assert.equal(movementWorkflowHelpTopicForAction('join_vessel'), null);
    });

    it('includes concise transfer vs redeploy guidance copy', () => {
        assert.match(WORKFLOW_HELP.transfer, /onboard/i);
        assert.match(WORKFLOW_HELP.redeploy, /disembarkation|final stage/i);
        assert.match(WORKFLOW_HELP.new_assignment, /No active mobilisation/i);
        assert.match(
            WORKFLOW_HELP.plan_future,
            /without starting operational movement/i,
        );
    });
});
