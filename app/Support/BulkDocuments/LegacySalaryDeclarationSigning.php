<?php

namespace App\Support\BulkDocuments;

final class LegacySalaryDeclarationSigning
{
    public const DOCUMENT_TYPE_KEY = 'salary_declaration';

    public const SIGNING_RETIREMENT_MESSAGE = 'Legacy Salary Declaration signing has been retired. Use a Company Template signing flow.';

    public const PUBLIC_SIGNING_RETIREMENT_MESSAGE = 'This legacy signing link has been retired. Please use the new document request sent by HR.';

    public const PUBLIC_SIGNING_UNAVAILABLE_MESSAGE = 'This signing link is no longer available. HR will send you a new document signing request.';

    public const GENERATION_RETIREMENT_MESSAGE = 'Legacy Salary Declaration generation has been retired. Use a Company Template from Documents → Templates.';

    public static function isDocumentType(string $key): bool
    {
        return $key === self::DOCUMENT_TYPE_KEY;
    }
}
