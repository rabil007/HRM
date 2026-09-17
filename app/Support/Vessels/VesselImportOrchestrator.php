<?php

namespace App\Support\Vessels;

use App\Imports\VesselsImport;
use App\Models\Client;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VesselImportOrchestrator
{
    public function __construct(
        private readonly VesselsImport $import,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     warnings: list<array{row: int, field: string, message: string}>,
     *     summary: array{
     *         total: int,
     *         valid: int,
     *         invalid: int,
     *         importable: int,
     *         skipped: int,
     *         warnings: int,
     *         creates: int,
     *         updates: int,
     *         deletes: int,
     *         errors: int
     *     }
     * }
     */
    public function preview(int $companyId, UploadedFile $file, ?User $user = null): array
    {
        $evaluation = $this->evaluateRows(
            $companyId,
            $this->import->parse($file),
            $user,
        );

        return [
            'rows' => collect($evaluation['rows'])
                ->map(function (array $row): array {
                    unset($row['vessel_model'], $row['attributes']);

                    return $row;
                })
                ->values()
                ->all(),
            'errors' => $evaluation['errors'],
            'warnings' => $evaluation['warnings'],
            'summary' => $evaluation['summary'],
        ];
    }

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     errors: list<array{row: int, field: string, message: string}>
     * }
     */
    public function execute(int $companyId, UploadedFile $file, ?User $user = null): array
    {
        $evaluation = $this->evaluateRows(
            $companyId,
            $this->import->parse($file),
            $user,
        );

        if ($evaluation['summary']['importable'] === 0) {
            throw ValidationException::withMessages([
                'file' => 'No valid rows were found to import.',
            ]);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($evaluation, $companyId, &$created, &$updated, &$skipped): void {
            foreach ($evaluation['rows'] as $row) {
                if (! empty($row['errors']) || $row['action'] === 'skip') {
                    $skipped++;

                    continue;
                }

                /** @var array<string, mixed> $attributes */
                $attributes = $row['attributes'];

                if ($row['action'] === 'create') {
                    Vessel::query()->create([
                        'company_id' => $companyId,
                        ...$attributes,
                    ]);
                    $created++;

                    continue;
                }

                /** @var Vessel $vessel */
                $vessel = $row['vessel_model'];
                $vessel->update($attributes);
                $updated++;
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $evaluation['errors'],
        ];
    }

    /**
     * @param  array{
     *     present_columns: list<string>,
     *     rows: list<array<string, mixed>>
     * }  $parsed
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     warnings: list<array{row: int, field: string, message: string}>,
     *     summary: array{
     *         total: int,
     *         valid: int,
     *         invalid: int,
     *         importable: int,
     *         skipped: int,
     *         warnings: int,
     *         creates: int,
     *         updates: int,
     *         deletes: int,
     *         errors: int
     *     }
     * }
     */
    private function evaluateRows(int $companyId, array $parsed, ?User $user): array
    {
        /** @var list<string> $presentColumns */
        $presentColumns = $parsed['present_columns'];
        $hasClientColumn = in_array('client', $presentColumns, true);

        $clientsByName = Client::query()
            ->get(['id', 'name', 'is_active'])
            ->keyBy(fn (Client $client): string => mb_strtolower(trim($client->name)));

        $vesselTypesByName = VesselType::query()
            ->get(['id', 'name'])
            ->keyBy(fn (VesselType $type): string => mb_strtolower(trim($type->name)));

        $companyVessels = Vessel::query()
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'client_id', 'vessel_type_id', 'grt', 'bhp', 'official_no', 'call_sign', 'imo_no', 'is_active']);

        $vesselsById = $companyVessels->keyBy('id');
        $vesselsByNormalizedName = $companyVessels->keyBy(
            fn (Vessel $vessel): string => Vessel::normalizeName($vessel->name),
        );

        $seenVesselIds = [];
        $seenNormalizedNames = [];
        $rows = [];
        $errors = [];
        $warnings = [];
        $creates = 0;
        $updates = 0;
        $invalid = 0;
        $skipped = 0;

        $canCreate = $user?->can('crew_operations.vessels.create') ?? false;
        $canUpdate = $user?->can('crew_operations.vessels.update') ?? false;

        foreach ($parsed['rows'] as $parsedRow) {
            $rowNumber = (int) $parsedRow['row'];
            $rowErrors = [];
            $action = 'skip';

            $name = trim((string) ($parsedRow['name'] ?? ''));
            if ($name === '') {
                $rowErrors[] = $this->error($rowNumber, 'name', 'Name is required.');
            }

            $vesselIdRaw = $parsedRow['vessel_id'];
            $vesselId = null;
            $vessel = null;
            $isUpdate = $vesselIdRaw !== null;

            if ($isUpdate) {
                if (! ctype_digit($vesselIdRaw)) {
                    $rowErrors[] = $this->error(
                        $rowNumber,
                        'vessel_id',
                        "Vessel ID {$vesselIdRaw} is invalid or does not belong to this company.",
                    );
                } else {
                    $vesselId = (int) $vesselIdRaw;
                    $vessel = $vesselsById->get($vesselId);

                    if ($vessel === null) {
                        $rowErrors[] = $this->error(
                            $rowNumber,
                            'vessel_id',
                            "Vessel ID {$vesselId} is invalid or does not belong to this company.",
                        );
                    } elseif (isset($seenVesselIds[$vesselId])) {
                        $rowErrors[] = $this->error(
                            $rowNumber,
                            'vessel_id',
                            "Duplicate vessel_id {$vesselId} in upload (first seen on row {$seenVesselIds[$vesselId]}).",
                        );
                    } else {
                        $seenVesselIds[$vesselId] = $rowNumber;
                        $action = 'update';
                    }
                }
            } else {
                $action = 'create';
            }

            $typeName = trim((string) ($parsedRow['vessel_type'] ?? ''));
            $vesselType = $typeName === ''
                ? null
                : $vesselTypesByName->get(mb_strtolower($typeName));

            if ($vesselType === null) {
                $rowErrors[] = $this->error(
                    $rowNumber,
                    'vessel_type',
                    $typeName === ''
                        ? 'Vessel type is required.'
                        : "Unknown vessel type \"{$typeName}\".",
                );
            }

            $client = null;
            $clientName = $parsedRow['client'];

            if ($action === 'create') {
                if ($clientName === null || trim($clientName) === '') {
                    $rowErrors[] = $this->error($rowNumber, 'client', 'Client is required when creating a vessel.');
                } else {
                    $client = $clientsByName->get(mb_strtolower(trim($clientName)));

                    if ($client === null || ! $client->is_active) {
                        $rowErrors[] = $this->error(
                            $rowNumber,
                            'client',
                            "Unknown or inactive client \"{$clientName}\".",
                        );
                    }
                }

                if ($name !== '') {
                    $normalizedName = Vessel::normalizeName($name);

                    if (isset($seenNormalizedNames[$normalizedName])) {
                        $rowErrors[] = $this->error(
                            $rowNumber,
                            'name',
                            "{$name} appears more than once in this upload (first seen on row {$seenNormalizedNames[$normalizedName]}).",
                        );
                    } else {
                        $seenNormalizedNames[$normalizedName] = $rowNumber;
                    }

                    $existingByName = $vesselsByNormalizedName->get($normalizedName);

                    if ($existingByName !== null) {
                        $rowErrors[] = $this->error(
                            $rowNumber,
                            'name',
                            "{$name} already exists. Include vessel_id {$existingByName->id} to update this Vessel instead of creating another record.",
                        );
                    }
                }
            } elseif ($hasClientColumn) {
                if ($clientName === null || trim($clientName) === '') {
                    if ($vessel instanceof Vessel && $vessel->client_id !== null) {
                        $rowErrors[] = $this->error(
                            $rowNumber,
                            'client',
                            'An assigned client cannot be cleared. Select another client instead.',
                        );
                    }
                } else {
                    $client = $clientsByName->get(mb_strtolower(trim($clientName)));

                    if ($client === null) {
                        $rowErrors[] = $this->error(
                            $rowNumber,
                            'client',
                            "Unknown or inactive client \"{$clientName}\".",
                        );
                    } elseif ($vessel instanceof Vessel) {
                        $existingClientId = $vessel->client_id !== null ? (int) $vessel->client_id : null;
                        $isSameClient = $existingClientId !== null && $existingClientId === (int) $client->id;

                        if (! $isSameClient && ! $client->is_active) {
                            $rowErrors[] = $this->error(
                                $rowNumber,
                                'client',
                                "Unknown or inactive client \"{$clientName}\".",
                            );
                        }
                    }
                }
            }

            if ($name !== '' && $vessel instanceof Vessel) {
                $conflict = $vesselsByNormalizedName->get(Vessel::normalizeName($name));

                if ($conflict !== null && (int) $conflict->id !== (int) $vessel->id) {
                    $rowErrors[] = $this->error(
                        $rowNumber,
                        'name',
                        "Another vessel named \"{$name}\" already exists.",
                    );
                }
            }

            if ($action === 'create' && ! $canCreate) {
                $rowErrors[] = $this->error($rowNumber, 'vessel_id', 'You are not allowed to create vessels.');
            }

            if ($action === 'update' && ! $canUpdate) {
                $rowErrors[] = $this->error($rowNumber, 'vessel_id', 'You are not allowed to update vessels.');
            }

            $attributes = [];

            if ($rowErrors === [] && $vesselType instanceof VesselType) {
                if ($action === 'create') {
                    $attributes = $this->attributesForCreate($parsedRow, $presentColumns, $client, $vesselType, $name);
                } elseif ($vessel instanceof Vessel) {
                    $attributes = $this->attributesForUpdate(
                        $parsedRow,
                        $presentColumns,
                        $hasClientColumn,
                        $client,
                        $vesselType,
                        $name,
                        $vessel,
                    );
                }
            }

            if ($rowErrors !== []) {
                $invalid++;
                $errors = [...$errors, ...$rowErrors];
            } elseif ($action === 'create') {
                $creates++;
            } elseif ($action === 'update') {
                $updates++;
            } else {
                $skipped++;
            }

            $rows[] = [
                'row' => $rowNumber,
                'vessel_id' => $vesselId,
                'client' => $clientName,
                'name' => $name !== '' ? $name : null,
                'vessel_type' => $typeName !== '' ? $typeName : null,
                'imo_no' => $parsedRow['imo_no'],
                'official_no' => $parsedRow['official_no'],
                'call_sign' => $parsedRow['call_sign'],
                'grt' => $parsedRow['grt'],
                'bhp' => $parsedRow['bhp'],
                'is_active' => $parsedRow['is_active'],
                'action' => $rowErrors === [] ? $action : 'skip',
                'errors' => $rowErrors,
                'vessel_model' => $vessel,
                'attributes' => $attributes,
            ];
        }

        $total = count($parsed['rows']);
        $importable = $creates + $updates;

        return [
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => [
                'total' => $total,
                'valid' => $importable,
                'invalid' => $invalid,
                'importable' => $importable,
                'skipped' => $skipped,
                'warnings' => count($warnings),
                'creates' => $creates,
                'updates' => $updates,
                'deletes' => 0,
                'errors' => $invalid,
            ],
        ];
    }

    /**
     * @param  list<string>  $presentColumns
     * @return array<string, mixed>
     */
    private function attributesForCreate(
        array $parsedRow,
        array $presentColumns,
        ?Client $client,
        VesselType $vesselType,
        string $name,
    ): array {
        $attributes = [
            'name' => $name,
            'client_id' => $client?->id,
            'vessel_type_id' => $vesselType->id,
            'is_active' => true,
        ];

        if (in_array('imo_no', $presentColumns, true)) {
            $attributes['imo_no'] = $this->nullableString($parsedRow['imo_no']);
        }

        if (in_array('official_no', $presentColumns, true)) {
            $attributes['official_no'] = $this->nullableString($parsedRow['official_no']);
        }

        if (in_array('call_sign', $presentColumns, true)) {
            $attributes['call_sign'] = $this->nullableString($parsedRow['call_sign']);
        }

        if (in_array('grt', $presentColumns, true)) {
            $attributes['grt'] = $this->nullableGrt($parsedRow['grt']);
        }

        if (in_array('bhp', $presentColumns, true)) {
            $attributes['bhp'] = $this->nullableBhp($parsedRow['bhp']);
        }

        if (in_array('is_active', $presentColumns, true)) {
            $attributes['is_active'] = $this->parseActive($parsedRow['is_active']);
        }

        return $attributes;
    }

    /**
     * @param  list<string>  $presentColumns
     * @return array<string, mixed>
     */
    private function attributesForUpdate(
        array $parsedRow,
        array $presentColumns,
        bool $hasClientColumn,
        ?Client $client,
        VesselType $vesselType,
        string $name,
        Vessel $vessel,
    ): array {
        $attributes = [
            'name' => $name,
            'vessel_type_id' => $vesselType->id,
        ];

        if ($hasClientColumn && $client instanceof Client) {
            $attributes['client_id'] = $client->id;
        } elseif ($hasClientColumn && ($parsedRow['client'] === null || trim((string) $parsedRow['client']) === '')) {
            $attributes['client_id'] = null;
        }

        if (in_array('imo_no', $presentColumns, true)) {
            $attributes['imo_no'] = $this->nullableString($parsedRow['imo_no']);
        }

        if (in_array('official_no', $presentColumns, true)) {
            $attributes['official_no'] = $this->nullableString($parsedRow['official_no']);
        }

        if (in_array('call_sign', $presentColumns, true)) {
            $attributes['call_sign'] = $this->nullableString($parsedRow['call_sign']);
        }

        if (in_array('grt', $presentColumns, true)) {
            $attributes['grt'] = $this->nullableGrt($parsedRow['grt']);
        }

        if (in_array('bhp', $presentColumns, true)) {
            $attributes['bhp'] = $this->nullableBhp($parsedRow['bhp']);
        }

        if (in_array('is_active', $presentColumns, true)) {
            $attributes['is_active'] = $this->parseActive($parsedRow['is_active']);
        }

        return $attributes;
    }

    /**
     * @return array{row: int, field: string, message: string}
     */
    private function error(int $row, string $field, string $message): array
    {
        return [
            'row' => $row,
            'field' => $field,
            'message' => $message,
        ];
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableGrt(?string $value): ?float
    {
        if ($value === null || trim($value) === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function nullableBhp(?string $value): ?int
    {
        if ($value === null || trim($value) === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function parseActive(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        $normalized = mb_strtolower(trim($value));

        return $normalized === '' || in_array($normalized, ['1', 'yes', 'true', 'y', 'active'], true);
    }
}
