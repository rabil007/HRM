<?php

namespace App\Enums;

enum LeaveApprovalMode: string
{
    case AllRequired = 'all_required';
    case AnyRequired = 'any_required';

    public function label(): string
    {
        return match ($this) {
            self::AllRequired => 'All required approvers',
            self::AnyRequired => 'Any one required approver',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AllRequired => 'Required approvers act in order. Every required approver must approve before the leave request is approved.',
            self::AnyRequired => 'All required approvers can act immediately. The first approval approves the leave request; the first rejection rejects it.',
        };
    }

    public function shortHelper(): string
    {
        return match ($this) {
            self::AllRequired => 'Every required approver must approve in sequence.',
            self::AnyRequired => 'All required approvers can act immediately; the first decision completes the request.',
        };
    }

    public function requiredStepHelper(): string
    {
        return match ($this) {
            self::AllRequired => 'Must approve before the next required approver can act.',
            self::AnyRequired => 'Can approve or reject immediately. Only one required approver needs to make the decision.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function default(): self
    {
        return self::AllRequired;
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom((string) $value);
    }

    /**
     * Historical / missing values behave as all_required.
     */
    public static function fromStored(mixed $value): self
    {
        return self::tryFromMixed($value) ?? self::AllRequired;
    }
}
