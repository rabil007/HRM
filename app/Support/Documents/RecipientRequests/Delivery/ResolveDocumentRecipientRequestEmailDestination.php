<?php

namespace App\Support\Documents\RecipientRequests\Delivery;

use App\Enums\DocumentRecipientType;
use App\Models\DocumentRecipientRequest;
use App\Models\Employee;
use App\Models\User;
use App\Support\Documents\Signing\DocumentSigningInternalSignerEligibility;

final class ResolveDocumentRecipientRequestEmailDestination
{
    public function __construct(
        private DocumentSigningInternalSignerEligibility $signerEligibility = new DocumentSigningInternalSignerEligibility,
    ) {}

    /**
     * @return array{email: string|null, failure_category: string|null}
     */
    public function forRequest(DocumentRecipientRequest $request): array
    {
        $request->loadMissing(['employee', 'recipientUser']);

        if ($request->recipient_type === DocumentRecipientType::SubjectEmployee) {
            return $this->forSubjectEmployee($request->employee);
        }

        return $this->forInternalSigner($request);
    }

    /**
     * @return array{email: string|null, failure_category: string|null}
     */
    private function forSubjectEmployee(?Employee $employee): array
    {
        if (! $employee instanceof Employee) {
            return ['email' => null, 'failure_category' => 'recipient_email_missing'];
        }

        $email = $this->usableEmail($employee->work_email)
            ?? $this->usableEmail($employee->personal_email);

        if ($email === null) {
            return ['email' => null, 'failure_category' => 'recipient_email_missing'];
        }

        return ['email' => $email, 'failure_category' => null];
    }

    /**
     * @return array{email: string|null, failure_category: string|null}
     */
    private function forInternalSigner(DocumentRecipientRequest $request): array
    {
        $user = $request->recipientUser;

        if (! $user instanceof User) {
            return ['email' => null, 'failure_category' => 'recipient_no_longer_actionable'];
        }

        if (! $this->signerEligibility->isActionable($user, (int) $request->company_id)) {
            return ['email' => null, 'failure_category' => 'recipient_no_longer_actionable'];
        }

        if ((int) $request->recipient_user_id !== (int) $user->id) {
            return ['email' => null, 'failure_category' => 'recipient_no_longer_actionable'];
        }

        $email = $this->usableEmail($user->email);

        if ($email === null) {
            return ['email' => null, 'failure_category' => 'recipient_email_missing'];
        }

        return ['email' => $email, 'failure_category' => null];
    }

    private function usableEmail(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $email = trim($value);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }
}
