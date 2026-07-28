<?php

namespace App\Models;

use App\Enums\DocumentExpiryPushAlertStatus;
use Database\Factories\DocumentExpiryPushAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentExpiryPushAlert extends Model
{
    /** @use HasFactory<DocumentExpiryPushAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'employee_document_id',
        'user_id',
        'expiry_date_at_alert_time',
        'status',
        'queued_at',
        'sent_at',
        'failed_at',
        'failure_category',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expiry_date_at_alert_time' => 'date:Y-m-d',
            'status' => DocumentExpiryPushAlertStatus::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employeeDocument(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
