<?php

namespace App\Rules;

use App\Support\Auth\UserEmailIdentity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueUserEmail implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreUserId = null) {}

    /**
     * Reject an email already owned by another non-deleted User globally.
     *
     * Soft-deleted Users do not occupy the login identity, matching existing
     * organization-user unique checks that use `whereNull('deleted_at')`.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $query = app(UserEmailIdentity::class)->matchingNonDeleted($value);

        if ($this->ignoreUserId !== null) {
            $query->whereKeyNot($this->ignoreUserId);
        }

        if ($query->exists()) {
            $fail(__('validation.unique'));
        }
    }
}
