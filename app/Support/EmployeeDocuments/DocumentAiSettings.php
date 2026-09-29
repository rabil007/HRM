<?php

namespace App\Support\EmployeeDocuments;

use App\Enums\DocumentAiMode;
use App\Models\DocumentAiSetting;
use App\Models\User;
use App\Services\Settings\AiSettingsService;
use Illuminate\Support\Facades\DB;

final class DocumentAiSettings
{
    public function __construct(private AiSettingsService $aiSettings) {}

    public function modeForCompany(int $companyId): DocumentAiMode
    {
        $mode = DocumentAiSetting::query()
            ->where('company_id', $companyId)
            ->value('mode');

        return DocumentAiMode::tryFrom((string) $mode) ?? DocumentAiMode::Off;
    }

    public function providerAvailable(): bool
    {
        return $this->aiSettings->isProviderConfigured();
    }

    public function isEnabledForCompany(int $companyId): bool
    {
        return $this->modeForCompany($companyId) !== DocumentAiMode::Off;
    }

    public function isAvailableForCompany(int $companyId): bool
    {
        return $this->isEnabledForCompany($companyId) && $this->providerAvailable();
    }

    /**
     * @return array{mode: string, provider_available: bool, available: bool}
     */
    public function propsForCompany(int $companyId): array
    {
        $mode = $this->modeForCompany($companyId);
        $providerAvailable = $this->providerAvailable();

        return [
            'mode' => $mode->value,
            'provider_available' => $providerAvailable,
            'available' => $mode !== DocumentAiMode::Off && $providerAvailable,
        ];
    }

    public function updateForCompany(
        int $companyId,
        DocumentAiMode $mode,
        ?User $actor,
    ): DocumentAiSetting {
        return DB::transaction(function () use ($companyId, $mode, $actor): DocumentAiSetting {
            return DocumentAiSetting::query()->updateOrCreate(
                ['company_id' => $companyId],
                [
                    'mode' => $mode,
                    'updated_by' => $actor?->id,
                ],
            );
        });
    }
}
