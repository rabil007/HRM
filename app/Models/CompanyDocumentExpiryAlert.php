<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyDocumentExpiryAlert extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'company_document_id',
        'expiry_date_at_alert_time',
        'alerted_at',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date_at_alert_time' => 'date:Y-m-d',
            'alerted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyDocument(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class);
    }
}
