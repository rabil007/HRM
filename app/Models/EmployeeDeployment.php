<?php

namespace App\Models;

use Database\Factories\EmployeeDeploymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeDeployment extends Model
{
    /** @use HasFactory<EmployeeDeploymentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'rank_id' => 'integer',
            'client_id' => 'integer',
            'company_visa_type_id' => 'integer',
            'arrived_date' => 'date',
            'join_standby_from' => 'date',
            'join_standby_to' => 'date',
            'leave_standby_from' => 'date',
            'leave_standby_to' => 'date',
            'joined_date' => 'date',
            'disembarked_date' => 'date',
            'travelled_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function companyVisaType(): BelongsTo
    {
        return $this->belongsTo(CompanyVisaType::class);
    }
}
