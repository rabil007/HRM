<?php

namespace App\Enums;

enum DocumentRecipientRequestEventType: string
{
    case RequestCreated = 'request_created';
    case LinkViewed = 'link_viewed';
    case TokenRotated = 'token_rotated';
    case SignatureSubmitted = 'signature_submitted';
    case AcknowledgementSubmitted = 'acknowledgement_submitted';
    case RequestCancelled = 'request_cancelled';
    case RequestExpired = 'request_expired';
    case RequestSuperseded = 'request_superseded';
    case SignedVersionCreated = 'signed_version_created';
    case DocumentDownloaded = 'document_downloaded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
