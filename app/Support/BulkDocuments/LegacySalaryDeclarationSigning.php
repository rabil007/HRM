<?php

namespace App\Support\BulkDocuments;

final class LegacySalaryDeclarationSigning
{
    public const DOCUMENT_TYPE_KEY = 'salary_declaration';

    public const SIGNING_RETIREMENT_MESSAGE = 'Legacy Salary Declaration signing has been retired. Use a Company Template signing flow.';

    public const GENERATION_RETIREMENT_MESSAGE = 'Legacy Salary Declaration generation has been retired. Use a Company Template from Documents → Templates.';

    public static function isDocumentType(string $key): bool
    {
        return $key === self::DOCUMENT_TYPE_KEY;
    }
}
