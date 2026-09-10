<?php

namespace App\Support\Announcements;

use App\Models\Employee;
use App\Models\User;
use App\Services\WhatsAppService;

final class ResolveAnnouncementTestDestination
{
    public function __construct(private WhatsAppService $whatsApp) {}

    /**
     * @return array{
     *     email: array{available: bool, masked: string|null, value: string|null},
     *     whatsapp: array{available: bool, masked: string|null, value: string|null}
     * }
     */
    public function handle(User $user, int $companyId): array
    {
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->with('user:id,email')
            ->first();

        $email = $employee !== null
            ? ResolveEmployeeAnnouncementEmail::for($employee)
            : (filled($user->email) ? (string) $user->email : null);

        $phone = null;
        if ($employee !== null && filled($employee->phone)) {
            $normalized = $this->whatsApp->normalizePhone((string) $employee->phone);
            $phone = $normalized !== '' ? $normalized : null;
        }

        return [
            'email' => [
                'available' => filled($email),
                'masked' => filled($email) ? MaskAnnouncementContact::email((string) $email) : null,
                'value' => filled($email) ? (string) $email : null,
            ],
            'whatsapp' => [
                'available' => filled($phone),
                'masked' => filled($phone) ? MaskAnnouncementContact::phone((string) $phone) : null,
                'value' => filled($phone) ? (string) $phone : null,
            ],
        ];
    }

    /**
     * Frontend-safe destination shape (no raw contact values).
     *
     * @return array{
     *     email: array{available: bool, masked: string|null},
     *     whatsapp: array{available: bool, masked: string|null}
     * }
     */
    public function forFrontend(User $user, int $companyId): array
    {
        $resolved = $this->handle($user, $companyId);

        return [
            'email' => [
                'available' => $resolved['email']['available'],
                'masked' => $resolved['email']['masked'],
            ],
            'whatsapp' => [
                'available' => $resolved['whatsapp']['available'],
                'masked' => $resolved['whatsapp']['masked'],
            ],
        ];
    }
}
