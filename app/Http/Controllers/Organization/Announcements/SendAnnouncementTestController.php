<?php

namespace App\Http\Controllers\Organization\Announcements;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Announcements\SendAnnouncementTestRequest;
use App\Support\Announcements\Actions\SendAnnouncementTest;
use Illuminate\Http\JsonResponse;

class SendAnnouncementTestController extends Controller
{
    public function __invoke(
        SendAnnouncementTestRequest $request,
        SendAnnouncementTest $sendTest,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);

        $result = $sendTest->handle($companyId, $user, $request->validated());

        $email = $result['email'];
        $whatsapp = $result['whatsapp'];
        $attempted = array_filter([
            $email !== null && $email['attempted'] ? $email : null,
            $whatsapp !== null && $whatsapp['attempted'] ? $whatsapp : null,
        ]);
        $allSucceeded = $attempted !== [] && collect($attempted)->every(
            fn (array $channel): bool => $channel['success'],
        );
        $anySucceeded = collect($attempted)->contains(
            fn (array $channel): bool => $channel['success'],
        );

        $message = match (true) {
            $allSucceeded => 'Test sent. Your announcement has not been published.',
            $anySucceeded => 'Test completed with some failures. Your announcement has not been published.',
            default => 'Test could not be sent. No employees were notified and the announcement remains unpublished.',
        };

        return response()->json([
            'ok' => $anySucceeded,
            'message' => $message,
            'destinations' => $result['destinations'],
            'email' => $email,
            'whatsapp' => $whatsapp,
        ]);
    }
}
