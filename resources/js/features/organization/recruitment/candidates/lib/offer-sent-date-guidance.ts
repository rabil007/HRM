export type OfferSentDateGuidance = {
    canMarkSent: boolean;
    message: string | null;
};

/**
 * Frontend guidance mirroring CandidateOfferDateValidation::resolveAndValidateSentAt.
 * Backend remains authoritative. `companyToday` must be YYYY-MM-DD in company timezone.
 */
export function resolveOfferSentDateGuidance(options: {
    offerDate: string | null | undefined;
    sentAt: string | null | undefined;
    companyToday: string;
}): OfferSentDateGuidance {
    const companyToday = options.companyToday.trim();
    const offerDate = options.offerDate?.trim() || '';
    const sentAt = options.sentAt?.trim() || companyToday;

    if (offerDate !== '' && offerDate > companyToday) {
        return {
            canMarkSent: false,
            message:
                'This offer is dated in the future. You can keep it as Draft and record it as Sent on or after the Offer Date.',
        };
    }

    if (sentAt > companyToday) {
        return {
            canMarkSent: false,
            message:
                'Actual Sent Date cannot be in the future. Use the date the offer was actually sent to the candidate.',
        };
    }

    if (offerDate !== '' && sentAt < offerDate) {
        return {
            canMarkSent: false,
            message:
                'Actual Sent Date cannot be before the Offer Date recorded on the offer document.',
        };
    }

    return {
        canMarkSent: true,
        message:
            'Mark Sent records that the offer was sent outside the system. It does not send an email.',
    };
}
