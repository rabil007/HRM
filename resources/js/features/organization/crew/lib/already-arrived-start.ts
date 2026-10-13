/**
 * Optional Start Assignment shortcut: skip Pre-Mobilisation and open at P2A.
 * Backend Store/Bulk requests remain authoritative.
 */

export const ALREADY_ARRIVED_LABEL = 'Already Arrived — Skip Pre-Mobilisation';

export const ALREADY_ARRIVED_EXPLANATION =
    'The crew member has already arrived. The assignment will start directly in Join Standby (P2A), skipping Pre-Mobilisation.';

export const ACTUAL_ARRIVAL_DATE_LABEL = 'Actual Arrival Date';

export const ARRIVAL_DATE_OPTIONAL_LABEL = 'Arrival Date';

export const ACTUAL_ARRIVAL_DATE_HELPER =
    'Date the crew member actually arrived at the joining location.';

export const EXPECTED_ARRIVAL_DATE_HELPER =
    'Expected date the crew member will arrive at the joining location. Actual arrival is recorded later through Record Arrival.';

export const ACTUAL_ARRIVAL_REQUIRED_MESSAGE =
    'Actual Arrival Date is required when Already Arrived is selected.';

export const ACTUAL_ARRIVAL_FUTURE_MESSAGE =
    'Actual Arrival Date cannot be in the future.';
