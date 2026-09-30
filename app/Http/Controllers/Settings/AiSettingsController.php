<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Settings\AiSettingsService;
use App\Support\EmployeeDocuments\DocumentAiSettings;
use App\Support\Platform\PlatformAuthorization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingsController extends Controller
{
    public function __construct(
        private AiSettingsService $aiSettings,
        private DocumentAiSettings $documentAiSettings,
    ) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $canPlatformView = PlatformAuthorization::canView($user);
        $canManageDocumentAi = $user?->can('documents.ai.manage') ?? false;

        abort_unless($canPlatformView || $canManageDocumentAi, 403);

        $companyId = (int) $request->attributes->get('current_company_id');

        $props = [
            'platform_ai' => null,
            'document_ai' => null,
            'can' => [
                'platform_update' => PlatformAuthorization::canManage($user),
                'document_ai_manage' => $canManageDocumentAi,
            ],
        ];

        if ($canPlatformView) {
            $props['platform_ai'] = $this->aiSettings->forSettingsPage();
        }

        if ($companyId > 0) {
            $companyName = Company::query()->whereKey($companyId)->value('name');

            $props['document_ai'] = [
                ...$this->documentAiSettings->propsForCompany($companyId),
                'company_name' => is_string($companyName) && $companyName !== ''
                    ? $companyName
                    : null,
            ];
        }

        return Inertia::render('settings/ai', $props);
    }
}
