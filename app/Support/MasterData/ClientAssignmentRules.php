<?php

namespace App\Support\MasterData;

use App\Models\Client;
use App\Models\Project;
use App\Models\Vessel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Shared Client assignment and Client↔Vessel consistency rules.
 */
final class ClientAssignmentRules
{
    /**
     * Active, non-deleted Client for new business assignments.
     *
     * @return list<ValidationRule|string>
     */
    public static function activeClientIdRules(bool $required = true): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            $presence,
            'integer',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }

                $client = Client::query()->find((int) $value);

                if ($client === null) {
                    $fail('The selected client is invalid.');

                    return;
                }

                if (! $client->is_active) {
                    $fail('The selected client is inactive.');
                }
            },
        ];
    }

    /**
     * Allow keeping a legacy/inactive Client already stored on the record,
     * but require newly selected Clients to be active.
     *
     * @return list<ValidationRule|string>
     */
    public static function assignableClientIdRules(?int $existingClientId, bool $required = false): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            $presence,
            'integer',
            function (string $attribute, mixed $value, \Closure $fail) use ($existingClientId): void {
                if ($value === null || $value === '') {
                    return;
                }

                $clientId = (int) $value;
                $client = Client::query()->find($clientId);

                if ($client === null) {
                    $fail('The selected client is invalid.');

                    return;
                }

                if ($existingClientId !== null && $clientId === $existingClientId) {
                    return;
                }

                if (! $client->is_active) {
                    $fail('The selected client is inactive.');
                }
            },
        ];
    }

    public static function projectBelongsToClient(Validator $validator, ?int $clientId, ?int $projectId): void
    {
        if ($projectId === null || $projectId === 0) {
            return;
        }

        if ($clientId === null || $clientId === 0) {
            $validator->errors()->add(
                'project_id',
                'Select a client before assigning a project.',
            );

            return;
        }

        $matches = Project::query()
            ->whereKey($projectId)
            ->where('client_id', $clientId)
            ->exists();

        if (! $matches) {
            $validator->errors()->add(
                'project_id',
                'The selected project does not belong to the selected client.',
            );
        }
    }

    public static function vesselBelongsToClient(
        Validator $validator,
        int $companyId,
        ?int $clientId,
        ?int $vesselId,
        string $clientAttribute = 'client_id',
        string $vesselAttribute = 'vessel_id',
    ): void {
        if ($vesselId === null || $vesselId === 0) {
            return;
        }

        $vessel = Vessel::query()
            ->where('company_id', $companyId)
            ->whereKey($vesselId)
            ->first();

        if ($vessel === null) {
            $validator->errors()->add($vesselAttribute, 'The selected vessel is invalid.');

            return;
        }

        if ($vessel->client_id === null) {
            // Legacy unassigned vessel: allow pairing with any Client,
            // but prefer requiring explicit Client when submitted.
            return;
        }

        if ($clientId === null || $clientId === 0) {
            return;
        }

        if ((int) $vessel->client_id !== (int) $clientId) {
            $validator->errors()->add(
                $clientAttribute,
                'The selected client does not match the vessel’s current client.',
            );
        }
    }

    /**
     * Resolve operational Client from a company-scoped Vessel.
     */
    public static function resolveClientIdFromVessel(int $companyId, int $vesselId): ?int
    {
        $vessel = Vessel::query()
            ->where('company_id', $companyId)
            ->whereKey($vesselId)
            ->first();

        if ($vessel === null || $vessel->client_id === null) {
            return null;
        }

        return (int) $vessel->client_id;
    }
}
