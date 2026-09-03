<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientRole;
use App\Support\Documents\Signing\DocumentSignatureSlot;
use InvalidArgumentException;

final class DocumentSignaturePlacementValidator
{
    public const SCHEMA_V1 = 1;

    public const SCHEMA_V2 = 2;

    public const SCHEMA_V3 = 3;

    /**
     * @return array{schema_version: int, placements: list<array<string, mixed>>}
     */
    public static function emptyConfig(): array
    {
        return [
            'schema_version' => self::SCHEMA_V3,
            'placements' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array{id: string, type: string, role: string, slot_key?: string, page: int, x: float, y: float, width: float, height: float, required: bool}
     *
     * @throws InvalidArgumentException
     */
    public static function validateSubjectSignature(?array $config, int $sourcePageCount): array
    {
        return self::validateSignatureForRole($config, $sourcePageCount, DocumentRecipientRole::Subject);
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array{id: string, type: string, role: string, slot_key?: string, page: int, x: float, y: float, width: float, height: float, required: bool}
     *
     * @throws InvalidArgumentException
     */
    public static function validateCompanySignatorySignature(?array $config, int $sourcePageCount): array
    {
        return self::validateSignatureForRole($config, $sourcePageCount, DocumentRecipientRole::CompanySignatory);
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array{
     *     schema_version: int,
     *     placements: list<array{id: string, type: string, role: string, slot_key?: string, page: int, x: float, y: float, width: float, height: float, required: bool}>
     * }
     *
     * @throws InvalidArgumentException
     */
    public static function validateSignaturePlacementConfig(?array $config, int $sourcePageCount): array
    {
        if ($config === null || $config === []) {
            throw new InvalidArgumentException('Signature placement configuration is required.');
        }

        $schemaVersion = (int) ($config['schema_version'] ?? 0);

        if ($schemaVersion === self::SCHEMA_V1) {
            return self::validateSchemaV1Config($config, $sourcePageCount);
        }

        if ($schemaVersion === self::SCHEMA_V2) {
            return self::validateSchemaV2Config($config, $sourcePageCount);
        }

        if ($schemaVersion === self::SCHEMA_V3) {
            return self::validateSchemaV3Config($config, $sourcePageCount);
        }

        throw new InvalidArgumentException('Unsupported signature placement schema version.');
    }

    /**
     * Validate any readable schema, then normalize to v3 for Unified Designer saves.
     *
     * @param  array<string, mixed>|null  $config
     * @return array{
     *     schema_version: int,
     *     placements: list<array{id: string, type: string, role: string, slot_key: string, page: int, x: float, y: float, width: float, height: float, required: bool}>
     * }
     */
    public static function normalizeForDraftSave(?array $config, int $sourcePageCount): array
    {
        return self::toSchemaV3(self::validateSignaturePlacementConfig($config, $sourcePageCount));
    }

    /**
     * @param  array{
     *     schema_version: int,
     *     placements: list<array{id: string, type: string, role: string, slot_key?: string, page: int, x: float, y: float, width: float, height: float, required: bool}>
     * }  $validated
     * @return array{
     *     schema_version: int,
     *     placements: list<array{id: string, type: string, role: string, slot_key: string, page: int, x: float, y: float, width: float, height: float, required: bool}>
     * }
     */
    public static function toSchemaV3(array $validated): array
    {
        $placements = [];

        foreach ($validated['placements'] as $placement) {
            $slotKey = trim((string) ($placement['slot_key'] ?? ''));

            if ($slotKey === '') {
                $role = DocumentRecipientRole::tryFrom((string) $placement['role']);

                if ($role === null) {
                    throw new InvalidArgumentException('Signature placement role is required.');
                }

                $slotKey = DocumentSignatureSlot::defaultForRole($role);
            }

            $placement['slot_key'] = $slotKey;
            $placements[] = $placement;
        }

        return [
            'schema_version' => self::SCHEMA_V3,
            'placements' => $placements,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array{id: string, type: string, role: string, slot_key?: string, page: int, x: float, y: float, width: float, height: float, required: bool}
     *
     * @throws InvalidArgumentException
     */
    public static function validateSignatureForRole(
        ?array $config,
        int $sourcePageCount,
        DocumentRecipientRole $role,
    ): array {
        return self::validateSignatureForSlot(
            $config,
            $sourcePageCount,
            DocumentSignatureSlot::defaultForRole($role),
        );
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array{id: string, type: string, role: string, slot_key?: string, page: int, x: float, y: float, width: float, height: float, required: bool}
     *
     * @throws InvalidArgumentException
     */
    public static function validateSignatureForSlot(
        ?array $config,
        int $sourcePageCount,
        string $slotKey,
    ): array {
        if ($config === null || $config === []) {
            throw new InvalidArgumentException('Signature placement configuration is required.');
        }

        if (! DocumentSignatureSlot::isValid($slotKey)) {
            throw new InvalidArgumentException('Unsupported signature slot key.');
        }

        $matched = self::validateSignaturesForSlot($config, $sourcePageCount, $slotKey);

        return $matched[0];
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return list<array{id: string, type: string, role: string, slot_key?: string, page: int, x: float, y: float, width: float, height: float, required: bool}>
     *
     * @throws InvalidArgumentException
     */
    public static function validateSignaturesForSlot(
        ?array $config,
        int $sourcePageCount,
        string $slotKey,
    ): array {
        if ($config === null || $config === []) {
            throw new InvalidArgumentException('Signature placement configuration is required.');
        }

        if (! DocumentSignatureSlot::isValid($slotKey)) {
            throw new InvalidArgumentException('Unsupported signature slot key.');
        }

        $schemaVersion = (int) ($config['schema_version'] ?? 0);
        $validatedConfig = self::validateSignaturePlacementConfig($config, $sourcePageCount);
        $role = DocumentSignatureSlot::roleFor($slotKey);
        $defaultSlot = DocumentSignatureSlot::defaultForRole($role);
        $matched = [];

        if ($schemaVersion === self::SCHEMA_V1) {
            if ($slotKey !== $defaultSlot) {
                throw new InvalidArgumentException(
                    "Signature placement `{$slotKey}` is not configured for this document template version.",
                );
            }

            foreach ($validatedConfig['placements'] as $placement) {
                if ($placement['role'] === $role->value) {
                    $matched[] = $placement + ['slot_key' => $slotKey];
                }
            }
        } else {
            foreach ($validatedConfig['placements'] as $placement) {
                if (($placement['slot_key'] ?? null) === $slotKey) {
                    $matched[] = $placement;
                }
            }
        }

        if ($matched === []) {
            throw new InvalidArgumentException(
                "Signature placement `{$slotKey}` is not configured for this document template version.",
            );
        }

        return array_values($matched);
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return list<string>
     */
    public static function placementIdsForSlot(?array $config, string $slotKey): array
    {
        if (! is_array($config) || ! DocumentSignatureSlot::isValid($slotKey)) {
            return [];
        }

        $placements = $config['placements'] ?? [];

        if (! is_array($placements)) {
            return [];
        }

        $schemaVersion = (int) ($config['schema_version'] ?? 0);
        $role = DocumentSignatureSlot::roleFor($slotKey);
        $defaultSlot = DocumentSignatureSlot::defaultForRole($role);
        $ids = [];

        foreach ($placements as $placement) {
            if (! is_array($placement)) {
                continue;
            }

            $id = trim((string) ($placement['id'] ?? ''));

            if ($id === '') {
                continue;
            }

            if ($schemaVersion === self::SCHEMA_V1) {
                if ($slotKey === $defaultSlot && ($placement['role'] ?? null) === $role->value) {
                    $ids[] = $id;
                }

                continue;
            }

            $placementSlot = trim((string) ($placement['slot_key'] ?? ''));

            if ($placementSlot === '' && ($placement['role'] ?? null) === $role->value) {
                $placementSlot = $defaultSlot;
            }

            if ($placementSlot === $slotKey) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{schema_version: int, placements: list<array{id: string, type: string, role: string, page: int, x: float, y: float, width: float, height: float, required: bool}>}
     */
    private static function validateSchemaV1Config(array $config, int $sourcePageCount): array
    {
        $placements = $config['placements'] ?? [];

        if (! is_array($placements)) {
            throw new InvalidArgumentException('Signature placement list is invalid.');
        }

        $validated = [];
        $rolesSeen = [];
        $idsSeen = [];

        foreach ($placements as $placement) {
            if (! is_array($placement)) {
                throw new InvalidArgumentException('Signature placement entry is invalid.');
            }

            $parsed = self::parsePlacement($placement, $sourcePageCount, includeSlotKey: false);
            $role = $parsed['role'];

            if (! in_array($role, DocumentRecipientRole::signaturePlacementValues(), true)) {
                throw new InvalidArgumentException('Unsupported signature placement role.');
            }

            if (isset($rolesSeen[$role])) {
                throw new InvalidArgumentException("Only one {$role} signature placement is supported.");
            }

            if (isset($idsSeen[$parsed['id']])) {
                throw new InvalidArgumentException('Duplicate signature placement ids are not allowed.');
            }

            $rolesSeen[$role] = true;
            $idsSeen[$parsed['id']] = true;
            $validated[] = $parsed;
        }

        if ($validated === []) {
            throw new InvalidArgumentException('Signature placement configuration is required.');
        }

        return [
            'schema_version' => self::SCHEMA_V1,
            'placements' => $validated,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{schema_version: int, placements: list<array{id: string, type: string, role: string, slot_key: string, page: int, x: float, y: float, width: float, height: float, required: bool}>}
     */
    private static function validateSchemaV2Config(array $config, int $sourcePageCount): array
    {
        return self::validateSlotKeyedConfig($config, $sourcePageCount, self::SCHEMA_V2, allowDuplicateSlots: false);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{schema_version: int, placements: list<array{id: string, type: string, role: string, slot_key: string, page: int, x: float, y: float, width: float, height: float, required: bool}>}
     */
    private static function validateSchemaV3Config(array $config, int $sourcePageCount): array
    {
        return self::validateSlotKeyedConfig($config, $sourcePageCount, self::SCHEMA_V3, allowDuplicateSlots: true);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{schema_version: int, placements: list<array{id: string, type: string, role: string, slot_key: string, page: int, x: float, y: float, width: float, height: float, required: bool}>}
     */
    private static function validateSlotKeyedConfig(
        array $config,
        int $sourcePageCount,
        int $schemaVersion,
        bool $allowDuplicateSlots,
    ): array {
        $placements = $config['placements'] ?? [];

        if (! is_array($placements)) {
            throw new InvalidArgumentException('Signature placement list is invalid.');
        }

        $validated = [];
        $idsSeen = [];
        $slotsSeen = [];
        $managerOccurrences = [];
        $companyOccurrences = [];

        foreach ($placements as $placement) {
            if (! is_array($placement)) {
                throw new InvalidArgumentException('Signature placement entry is invalid.');
            }

            $parsed = self::parsePlacement($placement, $sourcePageCount, includeSlotKey: true);
            $role = DocumentRecipientRole::tryFrom($parsed['role']);
            $slotKey = (string) $parsed['slot_key'];

            if ($role === null || ! in_array($role->value, DocumentRecipientRole::signaturePlacementValues(), true)) {
                throw new InvalidArgumentException('Unsupported signature placement role.');
            }

            if (! DocumentSignatureSlot::isValid($slotKey)) {
                throw new InvalidArgumentException('Unsupported signature slot key.');
            }

            $slotRole = DocumentSignatureSlot::roleFor($slotKey);

            if ($slotRole !== $role) {
                throw new InvalidArgumentException('Signature slot role must match placement role.');
            }

            if (isset($idsSeen[$parsed['id']])) {
                throw new InvalidArgumentException('Duplicate signature placement ids are not allowed.');
            }

            if (! $allowDuplicateSlots && isset($slotsSeen[$slotKey])) {
                throw new InvalidArgumentException('Duplicate signature slot keys are not allowed.');
            }

            $occurrence = DocumentSignatureSlot::occurrenceFor($slotKey);

            if ($role === DocumentRecipientRole::Subject && $slotKey !== DocumentSignatureSlot::SUBJECT) {
                throw new InvalidArgumentException('Subject signature slot must be `subject`.');
            }

            if ($role === DocumentRecipientRole::Manager && ! isset($slotsSeen[$slotKey])) {
                $managerOccurrences[] = $occurrence;
            }

            if ($role === DocumentRecipientRole::CompanySignatory && ! isset($slotsSeen[$slotKey])) {
                $companyOccurrences[] = $occurrence;
            }

            $idsSeen[$parsed['id']] = true;
            $slotsSeen[$slotKey] = true;
            $validated[] = $parsed;
        }

        self::assertContiguousOccurrences($managerOccurrences, 'manager');
        self::assertContiguousOccurrences($companyOccurrences, 'company_signatory');

        return [
            'schema_version' => $schemaVersion,
            'placements' => $validated,
        ];
    }

    /**
     * @param  list<int>  $occurrences
     */
    private static function assertContiguousOccurrences(array $occurrences, string $label): void
    {
        if ($occurrences === []) {
            return;
        }

        sort($occurrences);
        $expected = range(1, count($occurrences));

        if ($occurrences !== $expected) {
            throw new InvalidArgumentException(
                "{$label} signature slots must be contiguous starting at 1 (no sparse slots).",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $placement
     * @return array{id: string, type: string, role: string, slot_key?: string, page: int, x: float, y: float, width: float, height: float, required: bool}
     *
     * @throws InvalidArgumentException
     */
    private static function parsePlacement(array $placement, int $sourcePageCount, bool $includeSlotKey): array
    {
        $type = (string) ($placement['type'] ?? '');

        if ($type !== 'signature') {
            throw new InvalidArgumentException('Unsupported signature placement type.');
        }

        $role = (string) ($placement['role'] ?? '');

        if ($role === '') {
            throw new InvalidArgumentException('Signature placement role is required.');
        }

        $id = trim((string) ($placement['id'] ?? ''));

        if ($id === '') {
            throw new InvalidArgumentException('Signature placement id is required.');
        }

        $page = (int) ($placement['page'] ?? 0);

        if ($page < 1 || $page > $sourcePageCount) {
            throw new InvalidArgumentException('Signature placement page is out of range.');
        }

        $x = (float) ($placement['x'] ?? -1);
        $y = (float) ($placement['y'] ?? -1);
        $width = (float) ($placement['width'] ?? 0);
        $height = (float) ($placement['height'] ?? 0);

        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            throw new InvalidArgumentException('Signature placement coordinates must be normalized between 0 and 1.');
        }

        if ($width <= 0 || $height <= 0 || $width > 1 || $height > 1) {
            throw new InvalidArgumentException('Signature placement dimensions are invalid.');
        }

        if ($x + $width > 1 || $y + $height > 1) {
            throw new InvalidArgumentException('Signature placement extends outside the page bounds.');
        }

        $parsed = [
            'id' => $id,
            'type' => $type,
            'role' => $role,
            'page' => $page,
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'required' => (bool) ($placement['required'] ?? true),
        ];

        if ($includeSlotKey) {
            $slotKey = trim((string) ($placement['slot_key'] ?? ''));

            if ($slotKey === '') {
                $roleEnum = DocumentRecipientRole::tryFrom($role);

                if ($roleEnum === null) {
                    throw new InvalidArgumentException('Signature placement role is required.');
                }

                $slotKey = DocumentSignatureSlot::defaultForRole($roleEnum);
            }

            $parsed['slot_key'] = $slotKey;
        }

        return $parsed;
    }
}
