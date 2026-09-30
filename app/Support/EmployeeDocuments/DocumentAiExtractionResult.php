<?php

namespace App\Support\EmployeeDocuments;

use InvalidArgumentException;

final readonly class DocumentAiExtractionResult
{
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

        $normalized = [];
        foreach ($fields as $name => $field) {
            if (! is_string($name) || ! is_array($field)) {
                throw new InvalidArgumentException('Invalid field.');
            }

            $value = $field['value'] ?? null;
            $fieldConfidence = $field['confidence'] ?? null;
            if ($value !== null && ! is_string($value)) {
                throw new InvalidArgumentException('Invalid field value.');
            }
            if ($fieldConfidence !== null && (! is_numeric($fieldConfidence) || (float) $fieldConfidence < 0 || (float) $fieldConfidence > 1)) {
                throw new InvalidArgumentException('Invalid field confidence.');
            }

            $normalized[$name] = [
                'value' => $value === null ? null : trim($value),
                'confidence' => $fieldConfidence === null ? null : (float) $fieldConfidence,
            ];
        }

        $warnings = $decoded['warnings'] ?? [];
        if (! is_array($warnings) || collect($warnings)->contains(fn ($warning) => ! is_string($warning))) {
            throw new InvalidArgumentException('Invalid warnings.');
        }

        return new self($type, (float) $confidence, $normalized, array_values($warnings));
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
