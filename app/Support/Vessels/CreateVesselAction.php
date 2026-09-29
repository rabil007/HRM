<?php

namespace App\Support\Vessels;

use App\Models\Vessel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

class CreateVesselAction
{
    public function __construct(private StoresVesselCertificate $certificateStore) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes, ?UploadedFile $certificate = null): Vessel
    {
        $data = Arr::except($attributes, ['certificate']);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['official_no'] = $this->nullableString($data['official_no'] ?? null);
        $data['call_sign'] = $this->nullableString($data['call_sign'] ?? null);
        $data['imo_no'] = $this->nullableString($data['imo_no'] ?? null);

        $vessel = Vessel::query()->create($data);

        if ($certificate !== null) {
            $vessel->update(
                $this->certificateStore->store(
                    $certificate,
                    (int) $vessel->id,
                ),
            );
        }

        return $vessel;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
