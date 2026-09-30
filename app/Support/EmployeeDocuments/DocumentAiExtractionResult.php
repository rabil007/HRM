<?php

namespace App\Support\EmployeeDocuments;

use InvalidArgumentException;

final readonly class DocumentAiExtractionResult
{
    public const MAX_FIELD_VALUE_LENGTH = 255;

    public const MAX_WARNINGS = 10;

    public const MAX_WARNING_LENGTH = 240;

    private const SUPPORTED_FIELDS = [
        'document_number',
        'issue_date',
        'expiry_date',
        'holder_name',
        'nationality',
        'issuing_country',
        'visa_type',
    ];

    /** @param array<string, array{value: string|null, confidence: float|null}> $fields */
    public function __construct(
        public string $documentType,
        public float $confidence,
        public array $fields,
        public array $warnings,
    ) {}

    /** @param array<string, mixed> $decoded */
    public static function fromDecoded(array $decoded): self
    {
        $type = (string) ($decoded['document_type'] ?? '');
        if (! in_array($type, ['passport', 'emirates_id', 'uae_visa', 'unknown'], true)) {
            throw new InvalidArgumentException('Invalid document type.');
        }

        $confidence = $decoded['confidence'] ?? null;
        if (! is_numeric($confidence) || (float) $confidence < 0 || (float) $confidence > 1) {
            throw new InvalidArgumentException('Invalid confidence.');
        }

        $fields = $decoded['fields'] ?? null;
        if (! is_array($fields)) {
            throw new InvalidArgumentException('Invalid fields.');
        }

        $warnings = $decoded['warnings'] ?? [];
        if (! is_array($warnings) || collect($warnings)->contains(fn ($warning) => ! is_string($warning))) {
            throw new InvalidArgumentException('Invalid warnings.');
        }

        $normalized = [];
        foreach ($fields as $name => $field) {
            if (! is_string($name) || ! is_array($field)) {
                throw new InvalidArgumentException('Invalid field.');
            }

            if (! in_array($name, self::SUPPORTED_FIELDS, true)) {
                continue;
            }

            $value = $field['value'] ?? null;
            $fieldConfidence = $field['confidence'] ?? null;
            if ($value !== null && ! is_string($value)) {
                throw new InvalidArgumentException('Invalid field value.');
            }
            if ($fieldConfidence !== null && (! is_numeric($fieldConfidence) || (float) $fieldConfidence < 0 || (float) $fieldConfidence > 1)) {
                throw new InvalidArgumentException('Invalid field confidence.');
            }

            $normalizedValue = $value === null ? null : trim($value);
            if ($normalizedValue !== null && mb_strlen($normalizedValue) > self::MAX_FIELD_VALUE_LENGTH) {
                $normalizedValue = mb_substr($normalizedValue, 0, self::MAX_FIELD_VALUE_LENGTH);
                $warnings[] = ucfirst(str_replace('_', ' ', $name)).' was truncated and needs manual review.';
            }

            if (in_array($name, ['issue_date', 'expiry_date'], true) && $normalizedValue !== null) {
                $isoDate = self::normalizeDateToIso($normalizedValue);

                if ($isoDate === null) {
                    $normalizedValue = null;
                    $warnings[] = ucfirst(str_replace('_', ' ', $name)).' could not be normalized and needs manual review.';
                } else {
                    $normalizedValue = $isoDate;
                }
            }

            $normalized[$name] = [
                'value' => $normalizedValue,
                'confidence' => $fieldConfidence === null ? null : (float) $fieldConfidence,
            ];
        }

        $boundedWarnings = collect($warnings)
            ->map(fn (string $warning): string => mb_substr(trim($warning), 0, self::MAX_WARNING_LENGTH))
            ->filter(fn (string $warning): bool => $warning !== '')
            ->unique()
            ->take(self::MAX_WARNINGS)
            ->values()
            ->all();

        return new self($type, (float) $confidence, $normalized, $boundedWarnings);
    }

    /**
     * Accept ISO dates and common UAE document day-first formats; always return Y-m-d.
     */
    private static function normalizeDateToIso(string $value): ?string
    {
        $candidate = trim($value);

        if ($candidate === '') {
            return null;
        }

        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $candidate);

            if ($date === false) {
                continue;
            }

            $errors = \DateTimeImmutable::getLastErrors();

            if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
                continue;
            }

            $iso = $date->format('Y-m-d');

            if ($format === '!Y-m-d' && $iso !== $candidate) {
                continue;
            }

            if (in_array($format, ['!d/m/Y', '!d-m-Y'], true)
                && $date->format(ltrim($format, '!')) !== $candidate) {
                continue;
            }

            return $iso;
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'document_type' => $this->documentType,
            'confidence' => $this->confidence,
            'fields' => $this->fields,
            'warnings' => $this->warnings,
        ];
    }
}
