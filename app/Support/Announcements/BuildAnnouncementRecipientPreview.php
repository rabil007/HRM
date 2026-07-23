<?php

namespace App\Support\Announcements;

use App\Enums\AnnouncementChannel;
use App\Services\WhatsAppService;
use Illuminate\Support\Collection;

final class BuildAnnouncementRecipientPreview
{
    public function __construct(private WhatsAppService $whatsApp) {}

    /**
     * @param  list<string>  $channels
     * @return array{
     *     selected_employees: int,
     *     in_app_available: int,
     *     email_available: int,
     *     whatsapp_available: int,
     *     missing_email: int,
     *     missing_phone: int
     * }
     */
    public function handle(Collection $employees, array $channels): array
    {
        $channelSet = collect($channels)->map(fn ($c) => (string) $c)->all();
        $wantsInApp = in_array(AnnouncementChannel::InApp->value, $channelSet, true);
        $wantsEmail = in_array(AnnouncementChannel::Email->value, $channelSet, true);
        $wantsWhatsApp = in_array(AnnouncementChannel::WhatsApp->value, $channelSet, true);

        $inApp = 0;
        $email = 0;
        $whatsapp = 0;
        $missingEmail = 0;
        $missingPhone = 0;

        foreach ($employees as $employee) {
            if ($wantsInApp && $employee->user_id !== null) {
                $inApp++;
            }

            $resolvedEmail = ResolveEmployeeAnnouncementEmail::for($employee);
            if ($wantsEmail) {
                if (filled($resolvedEmail)) {
                    $email++;
                } else {
                    $missingEmail++;
                }
            }

            $phone = filled($employee->phone)
                ? $this->whatsApp->normalizePhone((string) $employee->phone)
                : '';

            if ($wantsWhatsApp) {
                if ($phone !== '' && preg_match('/^[1-9]\d{6,14}$/', $phone) === 1) {
                    $whatsapp++;
                } else {
                    $missingPhone++;
                }
            }
        }

        return [
            'selected_employees' => $employees->count(),
            'in_app_available' => $inApp,
            'email_available' => $email,
            'whatsapp_available' => $whatsapp,
            'missing_email' => $missingEmail,
            'missing_phone' => $missingPhone,
        ];
    }
}
