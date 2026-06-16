<?php

namespace App\Models;

use App\Support\Hikvision\HikvisionPersonPhotoStorage;
use App\Support\Users\UserAvatar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HikvisionPerson extends Model
{
    protected $table = 'hikvision_persons';

    public const CREDENTIAL_FINGERPRINT = 'fingerprint';

    public const CREDENTIAL_PIN = 'pin';

    public const CREDENTIAL_NONE = 'none';

    protected $fillable = [
        'person_id',
        'group_id',
        'person_code',
        'first_name',
        'last_name',
        'full_name',
        'phone',
        'email',
        'photo_path',
        'photo_remote_key',
        'has_fingerprint',
        'has_pin',
        'raw_payload',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_fingerprint' => 'boolean',
            'has_pin' => 'boolean',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $apiPerson
     */
    public static function upsertFromApi(array $apiPerson, ?self $preserveCredentialsFrom = null): self
    {
        $personInfo = is_array($apiPerson['personInfo'] ?? null) ? $apiPerson['personInfo'] : [];
        $fingerList = is_array($apiPerson['fingerList'] ?? null) ? $apiPerson['fingerList'] : [];
        $hasFingerprint = $fingerList !== [];
        $hasPin = filled($apiPerson['pinCode'] ?? null);

        if ($preserveCredentialsFrom !== null) {
            if (! $hasFingerprint && $preserveCredentialsFrom->has_fingerprint) {
                $hasFingerprint = true;
            }

            if (! $hasPin && $preserveCredentialsFrom->has_pin) {
                $hasPin = true;
            }
        }

        $firstName = trim((string) ($personInfo['firstName'] ?? ''));
        $lastName = trim((string) ($personInfo['lastName'] ?? ''));
        $fullName = trim($firstName.' '.$lastName);

        if ($fullName === '') {
            $fullName = $firstName !== '' ? $firstName : $lastName;
        }

        $headPicUrl = null;
        $shouldSyncPhoto = false;

        if (array_key_exists('headPicUrl', $personInfo)) {
            $shouldSyncPhoto = true;
            $headPicUrl = filled($personInfo['headPicUrl']) ? (string) $personInfo['headPicUrl'] : null;
        }

        $person = self::query()->updateOrCreate(
            ['person_id' => (string) ($personInfo['personId'] ?? '')],
            [
                'group_id' => filled($personInfo['groupId'] ?? null) ? (string) $personInfo['groupId'] : null,
                'person_code' => filled($personInfo['personCode'] ?? null) ? (string) $personInfo['personCode'] : null,
                'first_name' => $firstName !== '' ? $firstName : null,
                'last_name' => $lastName !== '' ? $lastName : null,
                'full_name' => $fullName !== '' ? $fullName : null,
                'phone' => filled($personInfo['phone'] ?? null) ? (string) $personInfo['phone'] : null,
                'email' => filled($personInfo['email'] ?? null) ? (string) $personInfo['email'] : null,
                'has_fingerprint' => $hasFingerprint,
                'has_pin' => $hasPin,
                'raw_payload' => [
                    'personInfo' => $personInfo,
                    'finger_count' => count($fingerList),
                    'has_pin' => $hasPin,
                ],
                'synced_at' => now(),
            ],
        );

        if ($shouldSyncPhoto) {
            HikvisionPersonPhotoStorage::syncFromRemoteUrl($person, $headPicUrl);
        }

        return $person->fresh() ?? $person;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return UserAvatar::url($this->photo_path);
    }

    /**
     * @return BelongsTo<HikvisionPersonGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(HikvisionPersonGroup::class, 'group_id', 'group_id');
    }

    /**
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * @return list<string>
     */
    public static function credentialFilterOptions(): array
    {
        return [
            self::CREDENTIAL_FINGERPRINT,
            self::CREDENTIAL_PIN,
            self::CREDENTIAL_NONE,
        ];
    }

    /**
     * @param  array{search?: string, group?: string, credential?: string}  $filters
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('full_name', 'like', '%'.$search.'%')
                    ->orWhere('person_code', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        $group = trim((string) ($filters['group'] ?? ''));

        if ($group !== '') {
            if ($group === HikvisionPersonGroup::UNASSIGNED_GROUP_VALUE) {
                $query->whereNull('group_id');
            } else {
                $query->where('group_id', $group);
            }
        }

        $credential = trim((string) ($filters['credential'] ?? ''));

        if ($credential !== '' && in_array($credential, self::credentialFilterOptions(), true)) {
            match ($credential) {
                self::CREDENTIAL_FINGERPRINT => $query->where('has_fingerprint', true),
                self::CREDENTIAL_PIN => $query->where('has_pin', true),
                self::CREDENTIAL_NONE => $query
                    ->where('has_fingerprint', false)
                    ->where('has_pin', false),
            };
        }

        return $query;
    }
}
