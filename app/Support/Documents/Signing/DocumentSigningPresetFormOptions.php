<?php

namespace App\Support\Documents\Signing;

use App\Support\Documents\RecipientRequests\DocumentRecipientSignatoryOptionsQuery;

final class DocumentSigningPresetFormOptions
{
    public function __construct(
        private DocumentRecipientSignatoryOptionsQuery $signatoryOptions,
    ) {}

    /**
     * @return array{users: list<array{id: int, name: string, email: string|null}>}
     */
    public function forCompany(int $companyId): array
    {
        return [
            'users' => $this->signatoryOptions->forCompany($companyId),
        ];
    }
}
