<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Support\LogOptions;

class Project extends Model
{
    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(function (Project $project): void {
            $project->syncLegacyClientToPivot();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'client_id',
                'title',
                'is_active',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_project')
            ->orderBy('clients.name')
            ->withTimestamps();
    }

    private function syncLegacyClientToPivot(): void
    {
        if ($this->client_id === null || ! Schema::hasTable('client_project')) {
            return;
        }

        $this->clients()->syncWithoutDetaching([(int) $this->client_id]);
    }
}
