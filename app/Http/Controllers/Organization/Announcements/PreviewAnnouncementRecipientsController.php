<?php

namespace App\Http\Controllers\Organization\Announcements;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Announcements\PreviewAnnouncementRecipientsRequest;
use App\Support\Announcements\BuildAnnouncementRecipientPreview;
use App\Support\Announcements\ResolveAnnouncementAudience;
use Illuminate\Http\JsonResponse;

class PreviewAnnouncementRecipientsController extends Controller
{
    public function __invoke(
        PreviewAnnouncementRecipientsRequest $request,
        ResolveAnnouncementAudience $resolveAudience,
        BuildAnnouncementRecipientPreview $buildPreview,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();

        $employees = $resolveAudience->handle($companyId, $data['audiences']);
        $preview = $buildPreview->handle($employees, $data['channels']);

        return response()->json($preview);
    }
}
