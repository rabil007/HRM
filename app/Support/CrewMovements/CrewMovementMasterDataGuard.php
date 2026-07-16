<?php

namespace App\Support\CrewMovements;

use App\Exceptions\CrewMovementException;
use Illuminate\Database\Eloquent\Model;

final class CrewMovementMasterDataGuard
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function assertUsable(int $companyId, string $modelClass, int $id, string $label): void
    {
        $model = $modelClass::query()->whereKey($id)->first();

        if ($model === null) {
            throw CrewMovementException::make(
                sprintf('Invalid %s.', $label),
                'invalid_master_'.$label,
            );
        }

        if (isset($model->is_active) && ! $model->is_active) {
            throw CrewMovementException::make(
                sprintf('The selected %s is inactive.', $label),
                'master_inactive',
            );
        }

        if (array_key_exists('company_id', $model->getAttributes())
            && $model->getAttribute('company_id') !== null
            && (int) $model->getAttribute('company_id') !== $companyId) {
            throw CrewMovementException::make(
                sprintf('The selected %s does not belong to this company.', $label),
                'master_company_mismatch',
            );
        }
    }
}
