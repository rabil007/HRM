<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\EmployeeDeploymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class EmployeeDeployment extends Model
{
    /** @use HasFactory<EmployeeDeploymentFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'employee_id',
                'rank_id',
                'client_id',
                'company_visa_type_id',
                'vessel_name',
                'arrived_date',
                'join_standby_from',
                'join_standby_to',
                'joined_date',
                'disembarked_date',
                'leave_standby_from',
                'leave_standby_to',
                'travelled_date',
                'remarks',
                'sort_order',
            ])
            ->logOnlyDirty();
    }

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
