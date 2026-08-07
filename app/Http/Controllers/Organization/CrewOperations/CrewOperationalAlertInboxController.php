<?php

namespace App\Http\Controllers\Organization\CrewOperations;

use App\Http\Controllers\Controller;
use App\Models\CrewOperationalAlertRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrewOperationalAlertInboxController extends Controller
{
    public function markRead(Request $request, CrewOperationalAlertRecipient $recipient): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && (int) $recipient->user_id === (int) $user->id, 404);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $recipient->company_id === $companyId, 404);

        if ($recipient->read_at === null) {
            $recipient->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }
}
