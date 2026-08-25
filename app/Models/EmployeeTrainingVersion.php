<?php

namespace App\Models;

use App\Support\EmployeeFiles\EmployeePrivateFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeTrainingVersion extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function training(): BelongsTo
    {
        return $this->belongsTo(EmployeeTraining::class, 'employee_training_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function replacer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }

    public function getFileUrlAttribute(): string
    {
        if (EmployeePrivateFile::isRemoteUrl($this->file_path)) {
            return (string) $this->file_path;
        }

        return route('organization.employees.training.certificate.version', [
            'employee' => $this->employee_id,
            'training' => $this->employee_training_id,
            'version' => $this,
            'inline' => 1,
        ]);
    }
}
