<?php

namespace App\Http\Controllers\Organization\Announcements;

use App\Exceptions\AnnouncementAiAssistUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Announcements\AnnouncementAiAssistRequest;
use App\Models\Company;
use App\Services\AnnouncementContentAssistInterpreter;
use Illuminate\Http\JsonResponse;

class AnnouncementAiAssistController extends Controller
{
    public function __invoke(
        AnnouncementAiAssistRequest $request,
        AnnouncementContentAssistInterpreter $interpreter,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();

        $companyName = (string) Company::query()
            ->whereKey($companyId)
            ->value('name');

        try {
            $result = $interpreter->assist([
                'action' => $validated['action'],
                'instructions' => $validated['instructions'] ?? null,
                'title' => $validated['title'] ?? null,
                'body_html' => $validated['body_html'] ?? null,
                'whatsapp_message' => $validated['whatsapp_message'] ?? null,
                'company_name' => $companyName !== '' ? $companyName : null,
            ]);
        } catch (AnnouncementAiAssistUnavailableException $e) {
            throw $e;
        }

        return response()->json([
            'ok' => true,
            'result' => $result,
        ]);
    }
}
