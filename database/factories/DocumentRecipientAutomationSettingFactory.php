<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\DocumentRecipientAutomationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRecipientAutomationSetting>
 */
class DocumentRecipientAutomationSettingFactory extends Factory
{
    protected $model = DocumentRecipientAutomationSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'reminders_enabled' => false,
            'reminder_days_before_expiry' => [7, 3, 1],
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function enabled(array $days = [7, 3, 1]): static
    {
        return $this->state(fn (): array => [
            'reminders_enabled' => true,
            'reminder_days_before_expiry' => $days,
        ]);
    }
}
