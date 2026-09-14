<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CompanyDocument\UpdateCompanyDocumentExpiryNotificationSettingRequest;
use App\Models\Company;
use App\Models\CompanyDocumentExpiryNotificationRecipient;
use App\Models\CompanyDocumentExpiryNotificationSetting;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyDocumentExpiryNotificationSettingController extends Controller
{
    public function show(
        Request $request,
        Company $company,
        CompanyDocumentAccess $access,
    ): JsonResponse {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['manage_notifications']);

        $setting = CompanyDocumentExpiryNotificationSetting::query()
            ->where('company_id', $company->id)
            ->with([
                'toRecipients.user:id,name,email',
                'ccRecipients.user:id,name,email',
            ])
            ->first();

        return response()->json($this->presentSetting($setting));
    }

    public function update(
        UpdateCompanyDocumentExpiryNotificationSettingRequest $request,
        Company $company,
        CompanyDocumentAccess $access,
    ): RedirectResponse {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['manage_notifications']);

        $data = $request->validated();
        $enabled = (bool) $data['enabled'];
        $toUserIds = array_values(array_unique((array) ($data['to_user_ids'] ?? [])));
        $ccUserIds = array_values(array_unique((array) ($data['cc_user_ids'] ?? [])));

        // Remove any CC IDs that are also in TO to prevent duplicates
        $ccUserIds = array_values(array_diff($ccUserIds, $toUserIds));

        // Validate that all supplied user IDs are active members of this company.
        $allIds = array_unique(array_merge($toUserIds, $ccUserIds));
        $validMemberIds = DB::table('company_user')
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereIn('user_id', $allIds)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Reject cross-company IDs silently (filter to valid members only).
        $toUserIds = array_values(array_intersect($toUserIds, $validMemberIds));
        $ccUserIds = array_values(array_intersect($ccUserIds, $validMemberIds));

        if ($enabled && $toUserIds === []) {
            return back()->withErrors([
                'to_user_ids' => 'At least one valid TO recipient is required to enable notifications.',
            ]);
        }

        DB::transaction(function () use ($company, $enabled, $toUserIds, $ccUserIds): void {
            $setting = CompanyDocumentExpiryNotificationSetting::query()->firstOrCreate(
                ['company_id' => $company->id],
                ['enabled' => false],
            );

            $setting->update(['enabled' => $enabled]);

            // Replace recipients atomically.
            $setting->recipients()->delete();

            $now = now();
            $inserts = [];

            foreach ($toUserIds as $userId) {
                $inserts[] = [
                    'setting_id' => $setting->id,
                    'user_id' => $userId,
                    'type' => 'to',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($ccUserIds as $userId) {
                $inserts[] = [
                    'setting_id' => $setting->id,
                    'user_id' => $userId,
                    'type' => 'cc',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($inserts !== []) {
                CompanyDocumentExpiryNotificationRecipient::query()->insert($inserts);
            }
        });

        return back()->with('success', 'Company document expiry notification settings saved.');
    }

    /**
     * @return array{enabled: bool, to_recipients: list<array{id: int, name: string, email: string}>, cc_recipients: list<array{id: int, name: string, email: string}>}
     */
    public function presentSetting(?CompanyDocumentExpiryNotificationSetting $setting): array
    {
        if ($setting === null) {
            return [
                'enabled' => false,
                'to_recipients' => [],
                'cc_recipients' => [],
            ];
        }

        return [
            'enabled' => $setting->enabled,
            'to_recipients' => $setting->toRecipients
                ->map(fn ($r) => [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                    'email' => $r->user->email,
                ])
                ->values()
                ->all(),
            'cc_recipients' => $setting->ccRecipients
                ->map(fn ($r) => [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                    'email' => $r->user->email,
                ])
                ->values()
                ->all(),
        ];
    }
}
