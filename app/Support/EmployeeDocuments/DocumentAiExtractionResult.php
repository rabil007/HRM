<?php

namespace App\Support\EmployeeDocuments;

use InvalidArgumentException;

final readonly class DocumentAiExtractionResult
{
    public const MAX_FIELD_VALUE_LENGTH = 255;

    public const MAX_DETECTED_LABEL_LENGTH = 120;

    public const MAX_WARNINGS = 10;

    public const MAX_WARNING_LENGTH = 240;

    public const SUPPORTED_DOCUMENT_TYPES = [
        'passport',
        'emirates_id',
        'labour_card',
        'seafarer_document',
        'driving_license',
        'uae_visa',
        'cicpa',
        'hse_passport',
        'insurance_document',
        'unknown',
    ];

    private const REJECTED_TOP_LEVEL_KEYS = [
        'document_type_id',
        'employee_id',
        'company_id',
        'user_id',
        'role_id',
        'permission_id',
    ];

    private const SUBTYPES_BY_TYPE = [
        'passport' => ['ordinary', 'diplomatic', 'service', 'official', 'emergency', 'unknown'],
        'seafarer_document' => ['seaman_book', 'cdc', 'discharge_book', 'seafarer_identity_document', 'unknown'],
        'uae_visa' => ['visit', 'residence', 'employment', 'tourist', 'transit', 'unknown'],
        'insurance_document' => ['card', 'policy', 'certificate', 'unknown'],
    ];

    private const UAE_DAY_FIRST_DOCUMENT_TYPES = [
        'emirates_id',
        'labour_card',
        'uae_visa',
        'cicpa',
    ];

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
        public ?string $documentSubtype,
        public ?string $detectedLabel,
        public float $confidence,
        public array $fields,
        public array $warnings,
    ) {}

    /** @param array<string, mixed> $decoded */
    public static function fromDecoded(array $decoded): self
    {
        foreach (self::REJECTED_TOP_LEVEL_KEYS as $rejectedKey) {
            if (array_key_exists($rejectedKey, $decoded)) {
                unset($decoded[$rejectedKey]);
            }
        }

        $type = (string) ($decoded['document_type'] ?? '');
        if (! in_array($type, self::SUPPORTED_DOCUMENT_TYPES, true)) {
            throw new InvalidArgumentException('Invalid document type.');
        }

        $subtype = self::normalizeSubtype($type, $decoded['document_subtype'] ?? null);

        $detectedLabel = $decoded['detected_label'] ?? null;
        if ($detectedLabel !== null && ! is_string($detectedLabel)) {
            throw new InvalidArgumentException('Invalid detected label.');
        }
        $detectedLabel = self::boundDetectedLabel($detectedLabel);

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
                $isoDate = self::normalizeDateToIso($normalizedValue, $type);

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

        return new self($type, $subtype, $detectedLabel, (float) $confidence, $normalized, $boundedWarnings);
    }

    private static function normalizeSubtype(string $type, mixed $subtype): ?string
    {
        if ($subtype === null || $subtype === '') {
            return null;
        }

        if (! is_string($subtype)) {
            return null;
        }

        $value = strtolower(trim($subtype));
        $allowed = self::SUBTYPES_BY_TYPE[$type] ?? null;

        if ($allowed === null) {
            return null;
        }

        if (! in_array($value, $allowed, true)) {
            return null;
        }

        return $value === 'unknown' ? null : $value;
    }

    private static function boundDetectedLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, self::MAX_DETECTED_LABEL_LENGTH);
    }

    /**
     * Accept ISO dates and common UAE document day-first formats; always return Y-m-d.
     */
    private static function normalizeDateToIso(string $value, string $documentType): ?string
    {
        $candidate = trim($value);

        if ($candidate === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);

            if ($date === false) {
                return null;
            }

            $errors = \DateTimeImmutable::getLastErrors();

            if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
                return null;
            }

            return $date->format('Y-m-d') === $candidate ? $candidate : null;
        }

        foreach (['!d/m/Y', '!d-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $candidate);

            if ($date === false) {
                continue;
            }

            $errors = \DateTimeImmutable::getLastErrors();

            if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
                continue;
            }

            if ($date->format(ltrim($format, '!')) !== $candidate) {
                continue;
            }

            if (in_array($documentType, self::UAE_DAY_FIRST_DOCUMENT_TYPES, true)) {
                return $date->format('Y-m-d');
            }

            if (! self::isUnambiguousDayFirstDate($candidate)) {
                return null;
            }

            return $date->format('Y-m-d');
        }

        return null;
    }

    private static function isUnambiguousDayFirstDate(string $candidate): bool
    {
        $parts = preg_split('/[\/-]/', $candidate) ?: [];

        if (count($parts) < 2) {
            return false;
        }

        $first = (int) $parts[0];
        $second = (int) $parts[1];

        return $first > 12 || $second > 12;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'document_type' => $this->documentType,
            'document_subtype' => $this->documentSubtype,
            'detected_label' => $this->detectedLabel,
            'confidence' => $this->confidence,
            'fields' => $this->fields,
            'warnings' => $this->warnings,
        ];
    }
}
