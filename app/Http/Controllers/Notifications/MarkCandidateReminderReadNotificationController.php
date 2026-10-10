<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentCandidateInternalReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarkCandidateReminderReadNotificationController extends Controller
{
    public function __invoke(
        Request $request,
        RecruitmentCandidateInternalReminder $reminder,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null && (int) $reminder->user_id === (int) $user->id, 404);

        if ($reminder->read_at === null) {
            $reminder->update(['read_at' => now()]);
        }

        return response()->json(['success' => true]);
    }
}
