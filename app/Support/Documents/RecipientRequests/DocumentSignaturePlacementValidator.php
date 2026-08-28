<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientRole;
use InvalidArgumentException;

final class DocumentSignaturePlacementValidator
{
    /**
     * @return array{schema_version: int, placements: list<array<string, mixed>>}
     */
    public static function emptyConfig(): array
    {
        return [
            'schema_version' => 1,
            'placements' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array{id: string, type: string, role: string, page: int, x: float, y: float, width: float, height: float, required: bool}
     *
     * @throws InvalidArgumentException
     */
    public static function validateSubjectSignature(?array $config, int $sourcePageCount): array
    {
        return self::validateSignatureForRole($config, $sourcePageCount, DocumentRecipientRole::Subject);
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array{id: string, type: string, role: string, page: int, x: float, y: float, width: float, height: float, required: bool}
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
     *     placements: list<array{id: string, type: string, role: string, page: int, x: float, y: float, width: float, height: float, required: bool}>
     * }
     *
     * @throws InvalidArgumentException
     */
    public static function validateSignaturePlacementConfig(?array $config, int $sourcePageCount): array
    {
        if ($config === null || $config === []) {
            throw new InvalidArgumentException('Signature placement configuration is required.');
        }

        if ((int) ($config['schema_version'] ?? 0) !== 1) {
            throw new InvalidArgumentException('Unsupported signature placement schema version.');
        }

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

            $parsed = self::parsePlacement($placement, $sourcePageCount);
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
            'schema_version' => 1,
            'placements' => $validated,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array{id: string, type: string, role: string, page: int, x: float, y: float, width: float, height: float, required: bool}
     *
     * @throws InvalidArgumentException
     */
    public static function validateSignatureForRole(
        ?array $config,
        int $sourcePageCount,
        DocumentRecipientRole $role,
    ): array {
        if ($config === null || $config === []) {
            throw new InvalidArgumentException('Signature placement configuration is required.');
        }

        if ((int) ($config['schema_version'] ?? 0) !== 1) {
            throw new InvalidArgumentException('Unsupported signature placement schema version.');
        }

        $placements = $config['placements'] ?? [];

        if (! is_array($placements)) {
            throw new InvalidArgumentException('Signature placement list is invalid.');
        }

        $matches = [];

        foreach ($placements as $placement) {
            if (! is_array($placement)) {
                throw new InvalidArgumentException('Signature placement entry is invalid.');
            }

            $parsed = self::parsePlacement($placement, $sourcePageCount);

            if ($parsed['role'] === $role->value) {
                $matches[] = $parsed;
            } elseif (! in_array($parsed['role'], DocumentRecipientRole::signaturePlacementValues(), true)) {
                throw new InvalidArgumentException('Unsupported signature placement role.');
            }
        }

        if ($matches === []) {
            throw new InvalidArgumentException('Signature placement configuration is required.');
        }

        if (count($matches) > 1) {
            throw new InvalidArgumentException("Only one {$role->value} signature placement is supported.");
        }

        return $matches[0];
    }

    /**
     * @param  array<string, mixed>  $placement
     * @return array{id: string, type: string, role: string, page: int, x: float, y: float, width: float, height: float, required: bool}
     *
     * @throws InvalidArgumentException
     */
    private static function parsePlacement(array $placement, int $sourcePageCount): array
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

        return [
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
    }
}
