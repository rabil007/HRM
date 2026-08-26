<?php

namespace App\Support\EmployeeDocuments;

use App\Models\DocumentRequirement;

final class DocumentRequirementSummary
{
    public static function label(?DocumentRequirement $requirement): string
    {
        if ($requirement === null || ! $requirement->is_active) {
            return 'Optional';
        }

        if ($requirement->required_for_all) {
            return 'All employees';
        }

        $departments = $requirement->relationLoaded('departments')
            ? $requirement->departments
            : $requirement->departments()->get(['departments.id', 'departments.name']);
        $positions = $requirement->relationLoaded('positions')
            ? $requirement->positions
            : $requirement->positions()->get(['positions.id', 'positions.title']);
        $ranks = $requirement->relationLoaded('ranks')
            ? $requirement->ranks
            : $requirement->ranks()->get(['ranks.id', 'ranks.name']);

        $parts = [];

        if ($departments->count() === 1) {
            $parts[] = (string) $departments->first()?->name;
        } elseif ($departments->count() > 1) {
            $parts[] = $departments->count().' departments';
        }

        if ($positions->count() === 1) {
            $parts[] = (string) $positions->first()?->title;
        } elseif ($positions->count() > 1) {
            $parts[] = $positions->count().' positions';
        }

        if ($ranks->count() === 1) {
            $parts[] = (string) $ranks->first()?->name;
        } elseif ($ranks->count() > 1) {
            $parts[] = $ranks->count().' ranks';
        }

        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return 'Optional';
        }

        return implode(' · ', $parts);
    }

    public static function auditPhrase(?DocumentRequirement $requirement): string
    {
        if ($requirement === null || ! $requirement->is_active) {
            return 'Optional';
        }

        if ($requirement->required_for_all) {
            return 'Required for all employees';
        }

        $names = [];

        $departments = $requirement->relationLoaded('departments')
            ? $requirement->departments
            : $requirement->departments()->get(['departments.id', 'departments.name']);
        $positions = $requirement->relationLoaded('positions')
            ? $requirement->positions
            : $requirement->positions()->get(['positions.id', 'positions.title']);
        $ranks = $requirement->relationLoaded('ranks')
            ? $requirement->ranks
            : $requirement->ranks()->get(['ranks.id', 'ranks.name']);

        foreach ($departments as $department) {
            $names[] = (string) $department->name;
        }

        foreach ($positions as $position) {
            $names[] = (string) $position->title;
        }

        foreach ($ranks as $rank) {
            $names[] = (string) $rank->name.' rank';
        }

        $names = array_values(array_filter($names, fn (string $name): bool => $name !== ''));

        if ($names === []) {
            return 'Optional';
        }

        return implode(' + ', $names);
    }
}
