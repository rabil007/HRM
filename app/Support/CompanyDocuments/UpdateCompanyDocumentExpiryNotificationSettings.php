<?php

namespace App\Support\CompanyDocuments;

use App\Models\Company;
use App\Models\CompanyDocumentExpiryNotificationRecipient;
use App\Models\CompanyDocumentExpiryNotificationSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class UpdateCompanyDocumentExpiryNotificationSettings
{
    /**
     * @param  list<int>  $toUserIds
     * @param  list<int>  $ccUserIds
     */
    public function handle(
        Company $company,
        bool $enabled,
        array $toUserIds,
        array $ccUserIds,
        User $actor,
    ): void {
        $toUserIds = array_values(array_unique(array_map('intval', $toUserIds)));
        $ccUserIds = array_values(array_diff(array_unique(array_map('intval', $ccUserIds)), $toUserIds));

        DB::transaction(function () use ($company, $enabled, $toUserIds, $ccUserIds, $actor): void {
            $setting = CompanyDocumentExpiryNotificationSetting::query()->firstOrCreate(
                ['company_id' => $company->id],
                ['enabled' => false],
            );

            $setting->load([
                'toRecipients.user:id,name,email',
                'ccRecipients.user:id,name,email',
            ]);

            $beforeTo = $this->snapshotRecipients($setting->toRecipients);
            $beforeCc = $this->snapshotRecipients($setting->ccRecipients);

            $setting->update(['enabled' => $enabled]);

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

            $afterUsers = User::query()
                ->whereIn('id', [...$toUserIds, ...$ccUserIds])
                ->get(['id', 'name', 'email'])
                ->keyBy('id');

            $afterTo = $this->snapshotFromIds($toUserIds, $afterUsers);
            $afterCc = $this->snapshotFromIds($ccUserIds, $afterUsers);

            if ($this->snapshotsEqual($beforeTo, $afterTo) && $this->snapshotsEqual($beforeCc, $afterCc)) {
                return;
            }

            $this->logRecipientChange($setting, $company, $actor, $beforeTo, $beforeCc, $afterTo, $afterCc);
        });
    }

    /**
     * @param  Collection<int, CompanyDocumentExpiryNotificationRecipient>  $recipients
     * @return list<array{id: int, name: string|null, email: string|null}>
     */
    private function snapshotRecipients(Collection $recipients): array
    {
        return $recipients
            ->map(fn (CompanyDocumentExpiryNotificationRecipient $recipient): array => [
                'id' => (int) $recipient->user_id,
                'name' => $recipient->user?->name,
                'email' => $recipient->user?->email,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @param  Collection<int, User>  $users
     * @return list<array{id: int, name: string|null, email: string|null}>
     */
    private function snapshotFromIds(array $userIds, Collection $users): array
    {
        return collect($userIds)
            ->map(function (int $userId) use ($users): array {
                $user = $users->get($userId);

                return [
                    'id' => $userId,
                    'name' => $user?->name,
                    'email' => $user?->email,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id: int, name: string|null, email: string|null}>  $before
     * @param  list<array{id: int, name: string|null, email: string|null}>  $after
     */
    private function snapshotsEqual(array $before, array $after): bool
    {
        return collect($before)->pluck('id')->values()->all()
            === collect($after)->pluck('id')->values()->all();
    }

    /**
     * @param  list<array{id: int, name: string|null, email: string|null}>  $beforeTo
     * @param  list<array{id: int, name: string|null, email: string|null}>  $beforeCc
     * @param  list<array{id: int, name: string|null, email: string|null}>  $afterTo
     * @param  list<array{id: int, name: string|null, email: string|null}>  $afterCc
     */
    private function logRecipientChange(
        CompanyDocumentExpiryNotificationSetting $setting,
        Company $company,
        User $actor,
        array $beforeTo,
        array $beforeCc,
        array $afterTo,
        array $afterCc,
    ): void {
        activity()
            ->useLog('documents')
            ->causedBy($actor)
            ->event('company_document_expiry_notification_recipients_updated')
            ->performedOn($setting)
            ->withProperties([
                'company_id' => $company->id,
                'before' => [
                    'to' => $beforeTo,
                    'cc' => $beforeCc,
                ],
                'after' => [
                    'to' => $afterTo,
                    'cc' => $afterCc,
                ],
            ])
            ->tap(function (Activity $activity) use ($company): void {
                $activity->company_id = (int) $company->id;
            })
            ->log('Company document expiry notification recipients updated');
    }
}
