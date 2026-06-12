<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Support\Attendance\LeaveRequestVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveRequestAttachmentController extends Controller
{
    public function __invoke(
        Request $request,
        LeaveRequest $leaveRequest,
        LeaveRequestVisibility $visibility,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $visibility->assertCanAccess($leaveRequest, $request->user(), $companyId);

        $attachments = $leaveRequest->attachments;

        if (! is_array($attachments) || $attachments === []) {
            abort(404);
        }

        $attachment = $attachments[0];
        $path = $attachment['path'] ?? null;

        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $name = (string) ($attachment['name'] ?? 'attachment');

        return Storage::disk('local')->download($path, $name);
    }
}
