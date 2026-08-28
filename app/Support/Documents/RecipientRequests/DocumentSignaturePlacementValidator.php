<?php

namespace App\Support\Documents\RecipientRequests;

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

        $subjectSignatures = [];

        foreach ($placements as $placement) {
            if (! is_array($placement)) {
                throw new InvalidArgumentException('Signature placement entry is invalid.');
            }

            $type = (string) ($placement['type'] ?? '');
            $role = (string) ($placement['role'] ?? '');

            if ($type !== 'signature') {
                throw new InvalidArgumentException('Unsupported signature placement type in Phase 6A.');
            }

            if ($role !== 'subject') {
                throw new InvalidArgumentException('Unsupported signature placement role in Phase 6A.');
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

            $subjectSignatures[] = [
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

        if ($subjectSignatures === []) {
            throw new InvalidArgumentException('Signature placement configuration is required.');
        }

        if (count($subjectSignatures) > 1) {
            throw new InvalidArgumentException('Only one subject signature placement is supported in Phase 6A.');
        }

        $ids = array_column($subjectSignatures, 'id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Duplicate signature placement ids are not allowed.');
        }

        return $subjectSignatures[0];
    }
}
