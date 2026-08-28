<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\DocumentRecipientRequest;

final class DocumentRecipientRequestAccess
{
    public static function assertInCompany(DocumentRecipientRequest $request, int $companyId): void
    {
        abort_unless((int) $request->company_id === $companyId, 404);
    }
}
