<?php

namespace App\Models;

use Database\Factories\DocumentRecipientAutomationSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRecipientAutomationSetting extends Model
{
    /** @use HasFactory<DocumentRecipientAutomationSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'reminders_enabled',
        'reminder_days_before_expiry',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'reminders_enabled' => 'boolean',
            'reminder_days_before_expiry' => 'array',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Read-only defaults when no settings row exists.
     *
     * @return array{reminders_enabled: false, reminder_days_before_expiry: list<int>}
     */
    public static function defaultAttributes(): array
    {
        return [
            'reminders_enabled' => false,
            'reminder_days_before_expiry' => [7, 3, 1],
        ];
    }

    /**
     * Read-only settings lookup. Does not create a database row when missing.
     */
    public static function findForCompany(int $companyId): self
    {
        $existing = self::query()
            ->where('company_id', $companyId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return new self([
            'company_id' => $companyId,
            ...self::defaultAttributes(),
        ]);
    }

    /**
     * Persistable settings row for the settings management UI / explicit writes.
     */
    public static function forCompany(int $companyId): self
    {
        return self::query()->firstOrCreate(
            ['company_id' => $companyId],
            self::defaultAttributes(),
        );
    }
}
