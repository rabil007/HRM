<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\Employee;
use App\Models\User;
use App\Support\Documents\Signing\DocumentSigningManagementChainResolver;
use Illuminate\Validation\ValidationException;

final class DocumentRecipientManagerResolver
{
    public function __construct(
        private DocumentSigningManagementChainResolver $chainResolver = new DocumentSigningManagementChainResolver,
    ) {}

    /**
     * Resolve the first actionable department manager for signing.
     *
     * @return array{manager: Employee, user: User}
     *
     * @throws ValidationException
     */
    public function resolveForEmployee(Employee $subjectEmployee, int $companyId): array
    {
        $resolved = $this->chainResolver->resolveAtPosition($subjectEmployee, $companyId, 1);

        return [
            'manager' => $resolved['manager'],
            'user' => $resolved['user'],
        ];
    }

    public function tryResolveForEmployee(Employee $subjectEmployee, int $companyId): ?array
    {
        try {
            return $this->resolveForEmployee($subjectEmployee, $companyId);
        } catch (ValidationException) {
            return null;
        }
    }
}
