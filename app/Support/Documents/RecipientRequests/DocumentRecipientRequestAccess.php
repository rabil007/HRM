<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientType;
use App\Models\DocumentRecipientRequest;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;

final class DocumentRecipientRequestAccess
{
    public static function assertInCompany(DocumentRecipientRequest $request, int $companyId): void
    {
        abort_unless((int) $request->company_id === $companyId, 404);
    }

    public static function assertPublicTokenRecipient(DocumentRecipientRequest $request): void
    {
        if (! $request->isPublicTokenRecipient()) {
            abort(404);
        }
    }

    public static function assertAssignedCompanySignatory(
        DocumentRecipientRequest $request,
        User $user,
        int $companyId,
    ): void {
        self::assertInCompany($request, $companyId);

        abort_unless($request->isInternalCompanySignatory(), 404);
        abort_unless((int) $request->recipient_user_id === (int) $user->id, 403);
        abort_unless($user->can('documents.recipient-requests.respond'), 403);

        $companyAccess = new ResolveCompanyAccess;

        abort_unless($companyAccess->hasAccessibleMembership($user, $companyId), 403);
    }

    public static function canViewRequest(
        DocumentRecipientRequest $request,
        ?User $user,
        int $companyId,
    ): bool {
        if ($user === null) {
            return false;
        }

        if ((int) $request->company_id !== $companyId) {
            return false;
        }

        if ($user->can('documents.recipient-requests.view')) {
            return true;
        }

        return $request->recipient_type === DocumentRecipientType::CompanyUser
            && (int) $request->recipient_user_id === (int) $user->id
            && $user->can('documents.recipient-requests.respond');
    }
}
