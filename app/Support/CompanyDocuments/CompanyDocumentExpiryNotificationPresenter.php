<?php

namespace App\Support\CompanyDocuments;

use App\Models\CompanyDocumentExpiryNotificationSetting;
use App\Models\User;
use Illuminate\Support\Collection;

class CompanyDocumentExpiryNotificationPresenter
{
    public function __construct(
        private readonly ResolveCompanyDocumentExpiryRecipients $resolveRecipients,
    ) {}

    public function settingForCompany(int $companyId): ?CompanyDocumentExpiryNotificationSetting
    {
        return CompanyDocumentExpiryNotificationSetting::query()
            ->where('company_id', $companyId)
            ->with([
                'toRecipients.user:id,name,email',
                'ccRecipients.user:id,name,email',
            ])
            ->first();
    }

    /**
     * @return array{notification_setting: array{enabled: bool, to_recipients: list<array{id: int, name: string, email: string}>, cc_recipients: list<array{id: int, name: string, email: string}>}, company_users: list<array{id: int, name: string, email: string}>}
     */
    public function presentIndex(int $companyId): array
    {
        $eligible = $this->resolveRecipients->eligibleUsersForCompany($companyId);
        $eligibleById = $eligible->keyBy('id');
        $setting = $this->settingForCompany($companyId);

        return [
            'notification_setting' => $this->presentWithEligible($setting, $eligibleById),
            'company_users' => $eligible
                ->map(fn (User $user): array => $this->presentUser($user))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{enabled: bool, to_recipients: list<array{id: int, name: string, email: string}>, cc_recipients: list<array{id: int, name: string, email: string}>}
     */
    public function present(?CompanyDocumentExpiryNotificationSetting $setting, int $companyId): array
    {
        return $this->presentWithEligible(
            $setting,
            $this->resolveRecipients->eligibleUsersForCompany($companyId)->keyBy('id'),
        );
    }

    /**
     * @return array{enabled: bool, to_recipients: list<array{id: int, name: string, email: string}>, cc_recipients: list<array{id: int, name: string, email: string}>}
     */
    public function presentForCompany(int $companyId): array
    {
        return $this->present($this->settingForCompany($companyId), $companyId);
    }

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    public function eligiblePickerUsers(int $companyId): array
    {
        return $this->resolveRecipients
            ->eligibleUsersForCompany($companyId)
            ->map(fn (User $user): array => $this->presentUser($user))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, User>  $eligibleById
     * @return array{enabled: bool, to_recipients: list<array{id: int, name: string, email: string}>, cc_recipients: list<array{id: int, name: string, email: string}>}
     */
    private function presentWithEligible(?CompanyDocumentExpiryNotificationSetting $setting, Collection $eligibleById): array
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
            'to_recipients' => $this->presentRecipients($setting->toRecipients, $eligibleById),
            'cc_recipients' => $this->presentRecipients($setting->ccRecipients, $eligibleById),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $recipients
     * @param  Collection<int, User>  $eligibleById
     * @return list<array{id: int, name: string, email: string}>
     */
    private function presentRecipients(Collection $recipients, Collection $eligibleById): array
    {
        return $recipients
            ->map(function ($recipient) use ($eligibleById): ?array {
                $user = $eligibleById->get((int) $recipient->user_id);

                return $user instanceof User ? $this->presentUser($user) : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, email: string}
     */
    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
