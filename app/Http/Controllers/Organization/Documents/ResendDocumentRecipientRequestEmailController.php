<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\DocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\ResendDocumentRecipientRequestEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ResendDocumentRecipientRequestEmailController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentRecipientRequest $recipientRequest,
        ResendDocumentRecipientRequestEmail $resend,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $delivery = $resend->handle($recipientRequest, $request->user(), $companyId);

        $message = match ($delivery->status) {
            DocumentRecipientRequestDeliveryStatus::Queued => 'Email queued for delivery.',
            DocumentRecipientRequestDeliveryStatus::Suppressed => match ($delivery->failure_category) {
                'email_template_disabled' => 'Request is ready, but document action email is disabled in Email Templates.',
                'recipient_email_missing' => 'Request is ready, but no usable recipient email address is available.',
                default => 'Email delivery is not available for this request.',
            },
            default => 'Email delivery updated.',
        };

        return back()->with('success', $message);
    }
}
