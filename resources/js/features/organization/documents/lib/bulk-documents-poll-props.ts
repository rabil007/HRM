export function bulkDocumentsPollOnlyProps(
    embeddedInRequests: boolean,
): string[] {
    if (embeddedInRequests) {
        return [
            'signature_payload.latest_run',
            'signature_payload.latest_email_batch',
            'signature_payload.latest_signature_repair_run',
            'signature_payload.counts',
            'signature_payload.signature_requests',
            'pagination',
            'flash',
        ];
    }

    return [
        'latest_run',
        'latest_email_batch',
        'latest_signature_repair_run',
        'counts',
        'employees',
        'signature_requests',
        'activity',
        'pagination',
        'flash',
    ];
}
