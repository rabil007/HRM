<?php

namespace App\Support\Announcements\Actions;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementStatus;
use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\Announcements\BuildAnnouncementEmailContent;
use App\Support\Announcements\BuildAnnouncementWhatsAppTemplatePayload;
use App\Support\Announcements\ResolveAnnouncementTestDestination;
use App\Support\Announcements\SanitizeAnnouncementHtml;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SendAnnouncementTest
{
    public function __construct(
        private ResolveAnnouncementTestDestination $destinations,
        private BuildAnnouncementEmailContent $emailContent,
        private BuildAnnouncementWhatsAppTemplatePayload $whatsAppPayload,
        private WhatsAppService $whatsApp,
    ) {}

    /**
     * @param  array{
     *     title: string,
     *     body_html: string,
     *     category: string,
     *     priority: string,
     *     whatsapp_link?: string|null,
     *     whatsapp_message?: string|null,
     *     whatsapp_template_id?: int|null,
     *     channels: list<string>,
     *     announcement_id?: int|null
     * }  $data
     * @return array{
     *     destinations: array{
     *         email: array{available: bool, masked: string|null},
     *         whatsapp: array{available: bool, masked: string|null}
     *     },
     *     email: array{attempted: bool, success: bool, message: string}|null,
     *     whatsapp: array{attempted: bool, success: bool, message: string}|null
     * }
     */
    public function handle(int $companyId, User $user, array $data): array
    {
        $channels = array_values(array_unique(array_map('strval', $data['channels'])));
        $this->assertTestChannels($channels);

        $resolved = $this->destinations->handle($user, $companyId);
        [$announcement, $sourceAnnouncementId] = $this->buildAnnouncement($companyId, $user, $data);

        $emailResult = null;
        $whatsappResult = null;

        if (in_array(AnnouncementChannel::Email->value, $channels, true)) {
            $emailResult = $this->sendEmail($announcement, $resolved['email']);
        }

        if (in_array(AnnouncementChannel::WhatsApp->value, $channels, true)) {
            $whatsappResult = $this->sendWhatsApp($announcement, $resolved['whatsapp']);
        }

        $attempted = array_values(array_filter([
            $emailResult !== null && $emailResult['attempted'] ? 'email' : null,
            $whatsappResult !== null && $whatsappResult['attempted'] ? 'whatsapp' : null,
        ]));

        if ($attempted === []) {
            throw ValidationException::withMessages([
                'channels' => 'No test destination is available for the selected channels.',
            ]);
        }

        $this->logTestSend(
            $companyId,
            $user,
            $sourceAnnouncementId,
            $channels,
            $emailResult,
            $whatsappResult,
        );

        return [
            'destinations' => [
                'email' => [
                    'available' => $resolved['email']['available'],
                    'masked' => $resolved['email']['masked'],
                ],
                'whatsapp' => [
                    'available' => $resolved['whatsapp']['available'],
                    'masked' => $resolved['whatsapp']['masked'],
                ],
            ],
            'email' => $emailResult,
            'whatsapp' => $whatsappResult,
        ];
    }

    /**
     * @param  array{
     *     title: string,
     *     body_html: string,
     *     category: string,
     *     priority: string,
     *     whatsapp_link?: string|null,
     *     whatsapp_message?: string|null,
     *     whatsapp_template_id?: int|null,
     *     channels: list<string>,
     *     announcement_id?: int|null
     * }  $data
     * @return array{0: Announcement, 1: int|null}
     */
    private function buildAnnouncement(int $companyId, User $user, array $data): array
    {
        $channels = array_values(array_unique(array_map('strval', $data['channels'])));
        $persistedId = array_key_exists('announcement_id', $data) && $data['announcement_id'] !== null
            ? (int) $data['announcement_id']
            : null;
        $company = Company::query()->whereKey($companyId)->first();

        $source = null;
        if ($persistedId !== null) {
            // Supplied IDs must resolve in the active company. Missing/cross-company → 404.
            $source = Announcement::query()
                ->whereKey($persistedId)
                ->where('company_id', $companyId)
                ->with(['attachments', 'company:id,name'])
                ->first();

            abort_unless($source !== null, 404);

            if (! $source->status->isEditable()) {
                throw ValidationException::withMessages([
                    'announcement_id' => 'Only draft or scheduled announcements can be tested.',
                ]);
            }
        }

        $usesWhatsApp = in_array(AnnouncementChannel::WhatsApp->value, $channels, true);
        $whatsAppMessage = isset($data['whatsapp_message']) ? trim((string) $data['whatsapp_message']) : '';

        $announcement = new Announcement([
            'company_id' => $companyId,
            'title' => $data['title'],
            'body_html' => SanitizeAnnouncementHtml::handle($data['body_html']),
            'category' => AnnouncementCategory::from($data['category']),
            'priority' => AnnouncementPriority::from($data['priority']),
            'status' => AnnouncementStatus::Draft,
            'channels' => $channels,
            'whatsapp_link' => $usesWhatsApp ? ($data['whatsapp_link'] ?? null) : null,
            'whatsapp_message' => $usesWhatsApp && $whatsAppMessage !== '' ? $whatsAppMessage : null,
            'whatsapp_template_id' => $usesWhatsApp
                ? ($data['whatsapp_template_id'] ?? null)
                : null,
            'created_by' => $user->id,
        ]);

        $announcement->setRelation(
            'attachments',
            $source?->attachments ?? collect(),
        );
        $announcement->setRelation('company', $company ?? $source?->company);

        return [$announcement, $source?->id];
    }

    /**
     * @param  array{available: bool, masked: string|null, value: string|null}  $destination
     * @return array{attempted: bool, success: bool, message: string}
     */
    private function sendEmail(Announcement $announcement, array $destination): array
    {
        if (! $destination['available'] || blank($destination['value'])) {
            return [
                'attempted' => false,
                'success' => false,
                'message' => 'No test email is available for your account.',
            ];
        }

        try {
            $content = $this->emailContent->preview($announcement);
            $subject = '[TEST] '.$content['subject'];

            Mail::to((string) $destination['value'])->send(new AnnouncementMail(
                subjectLine: $subject,
                bodyHtml: $content['html'],
            ));
        } catch (Throwable) {
            return [
                'attempted' => true,
                'success' => false,
                'message' => 'Test email could not be sent.',
            ];
        }

        return [
            'attempted' => true,
            'success' => true,
            'message' => 'Test email sent to '.($destination['masked'] ?? 'your account').'.',
        ];
    }

    /**
     * @param  array{available: bool, masked: string|null, value: string|null}  $destination
     * @return array{attempted: bool, success: bool, message: string}
     */
    private function sendWhatsApp(Announcement $announcement, array $destination): array
    {
        if (! $destination['available'] || blank($destination['value'])) {
            return [
                'attempted' => false,
                'success' => false,
                'message' => 'No valid employee phone is linked to your account.',
            ];
        }

        $payload = $this->whatsAppPayload->handle($announcement);

        if ($payload === null) {
            return [
                'attempted' => true,
                'success' => false,
                'message' => 'WhatsApp announcement template is not configured.',
            ];
        }

        try {
            $result = $this->whatsApp->sendTemplate(
                (string) $destination['value'],
                (string) $payload['template']->meta_name,
                (string) $payload['template']->meta_language,
                $payload['components'],
            );
        } catch (Throwable) {
            return [
                'attempted' => true,
                'success' => false,
                'message' => 'WhatsApp test could not be sent.',
            ];
        }

        if (! ($result['success'] ?? false)) {
            return [
                'attempted' => true,
                'success' => false,
                'message' => 'WhatsApp could not send the approved template.',
            ];
        }

        return [
            'attempted' => true,
            'success' => true,
            'message' => 'WhatsApp test sent to '.($destination['masked'] ?? 'your phone').'.',
        ];
    }

    /**
     * @param  list<string>  $channels
     * @param  array{attempted: bool, success: bool, message: string}|null  $emailResult
     * @param  array{attempted: bool, success: bool, message: string}|null  $whatsappResult
     */
    private function logTestSend(
        int $companyId,
        User $user,
        ?int $announcementId,
        array $channels,
        ?array $emailResult,
        ?array $whatsappResult,
    ): void {
        activity()
            ->useLog('announcements')
            ->event('announcement_test_sent')
            ->causedBy($user)
            ->withProperties([
                'announcement_id' => $announcementId,
                'channels_requested' => $channels,
                'email' => $emailResult === null ? null : [
                    'attempted' => $emailResult['attempted'],
                    'success' => $emailResult['success'],
                ],
                'whatsapp' => $whatsappResult === null ? null : [
                    'attempted' => $whatsappResult['attempted'],
                    'success' => $whatsappResult['success'],
                ],
            ])
            ->tap(fn ($entry) => $entry->company_id = $companyId)
            ->log('Announcement test sent');
    }

    /**
     * @param  list<string>  $channels
     */
    private function assertTestChannels(array $channels): void
    {
        $allowed = [
            AnnouncementChannel::Email->value,
            AnnouncementChannel::WhatsApp->value,
        ];

        if ($channels === []) {
            throw ValidationException::withMessages([
                'channels' => 'Select at least one test channel.',
            ]);
        }

        foreach ($channels as $channel) {
            if (! in_array($channel, $allowed, true)) {
                throw ValidationException::withMessages([
                    'channels' => 'Only Email and WhatsApp can be tested.',
                ]);
            }
        }
    }
}
