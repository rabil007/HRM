<?php

namespace App\Support\Activity;

use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Converts raw correction audit log entries into human-readable key/value maps
 * for display in the RecentActivityCard.
 *
 * This presenter is called exclusively by ActivityChangePresenter::presentLogs()
 * for activity logs whose `properties.event` starts with `correction_`. All other
 * activity events pass through the standard presentation path unchanged.
 *
 * Stored snapshot format: { field => { value: mixed, display: string|null } }
 * We use the `display` value exclusively; raw IDs or ISO timestamps are never
 * surfaced to the end-user.
 */
final class CrewCorrectionActivityPresenter
{
    /**
     * Human-readable field labels used in activity display.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'actual_start_at' => 'Actual Start',
        'actual_end_at' => 'Actual End',
        'remarks' => 'Remarks',
        'details.provider' => 'Training Provider',
        'details.course' => 'Training Course',
        'vessel_id' => 'Vessel',
        'rank_id' => 'Rank',
        'client_id' => 'Client',
        'company_visa_type_id' => 'Visa Type',
    ];

    /**
     * @param  Collection<string, mixed>  $properties
     * @return array<string, string>|null null signals "fall through to standard presenter"
     */
    public static function present(Activity $log, int $companyId): ?array
    {
        /** @var Collection<string, mixed> $properties */
        $properties = $log->properties;

        $event = $properties->get('event');

        if (! is_string($event)) {
            return null;
        }

        $correctionId = $properties->get('correction_id');

        if ($correctionId === null) {
            return self::fallback($event);
        }

        $id = is_int($correctionId) ? $correctionId : (ctype_digit((string) $correctionId) ? (int) $correctionId : null);

        if ($id === null || $id <= 0) {
            return self::fallback($event);
        }

        /** @var CrewMovementCorrection|null $correction */
        $correction = CrewMovementCorrection::query()
            ->where('company_id', $companyId)
            ->whereKey($id)
            ->with(['phase'])
            ->first();

        if ($correction === null) {
            return self::fallback($event);
        }

        return match ($event) {
            'correction_requested' => self::presentRequested($correction, $properties),
            'correction_approved' => self::presentApproved($correction, $properties),
            'correction_rejected' => self::presentRejected($correction, $properties),
            'correction_cancelled' => self::presentCancelled($correction, $properties),
            default => self::fallback($event),
        };
    }

    /**
     * @param  Collection<string, mixed>  $properties
     * @return array<string, string>
     */
    private static function presentRequested(CrewMovementCorrection $correction, Collection $properties): array
    {
        $result = [];

        $phaseLabel = self::phaseLabel($correction->phase);

        if ($phaseLabel !== null) {
            $result['Phase'] = $phaseLabel;
        }

        foreach (self::fieldChanges($correction->original_values ?? [], $correction->proposed_values ?? []) as $label => $change) {
            $result[$label] = $change;
        }

        /** @var string|null $reason */
        $reason = $correction->reason ?? $properties->get('reason');

        if (is_string($reason) && trim($reason) !== '') {
            $result['Reason'] = trim($reason);
        }

        return $result;
    }

    /**
     * @param  Collection<string, mixed>  $properties
     * @return array<string, string>
     */
    private static function presentApproved(CrewMovementCorrection $correction, Collection $properties): array
    {
        $result = [];

        $phaseLabel = self::phaseLabel($correction->phase);

        if ($phaseLabel !== null) {
            $result['Phase'] = $phaseLabel;
        }

        foreach (self::fieldChanges($correction->original_values ?? [], $correction->applied_values ?? []) as $label => $change) {
            $result[$label] = $change;
        }

        /** @var string|null $decisionNotes */
        $decisionNotes = $correction->decision_notes ?? $properties->get('decision_notes');

        if (is_string($decisionNotes) && trim($decisionNotes) !== '') {
            $result['Decision Note'] = trim($decisionNotes);
        }

        return $result;
    }

    /**
     * @param  Collection<string, mixed>  $properties
     * @return array<string, string>
     */
    private static function presentRejected(CrewMovementCorrection $correction, Collection $properties): array
    {
        $result = [];

        $phaseLabel = self::phaseLabel($correction->phase);

        if ($phaseLabel !== null) {
            $result['Phase'] = $phaseLabel;
        }

        $result['Status'] = 'Rejected';

        /** @var string|null $decisionNotes */
        $decisionNotes = $correction->decision_notes ?? $properties->get('decision_notes');

        if (is_string($decisionNotes) && trim($decisionNotes) !== '') {
            $result['Decision Note'] = trim($decisionNotes);
        }

        return $result;
    }

    /**
     * @param  Collection<string, mixed>  $properties
     * @return array<string, string>
     */
    private static function presentCancelled(CrewMovementCorrection $correction, Collection $properties): array
    {
        $result = [];

        $phaseLabel = self::phaseLabel($correction->phase);

        if ($phaseLabel !== null) {
            $result['Phase'] = $phaseLabel;
        }

        $result['Status'] = 'Cancelled';

        /** @var string|null $decisionNotes */
        $decisionNotes = $correction->decision_notes ?? $properties->get('decision_notes');

        if (is_string($decisionNotes) && trim($decisionNotes) !== '') {
            $result['Decision Note'] = trim($decisionNotes);
        }

        return $result;
    }

    private static function phaseLabel(?CrewAssignmentPhase $phase): ?string
    {
        if ($phase === null) {
            return null;
        }

        $code = $phase->phase_code;

        if (! $code instanceof CrewPhaseCode) {
            return null;
        }

        return strtoupper($code->value).' · '.$code->label();
    }

    /**
     * Build human-readable "old → new" strings for each known correctable field.
     *
     * Both $original and $proposed are stored snapshots in the shape:
     *   { field => { value: mixed, display: string|null } }
     *
     * We use the `display` value exclusively. Datetime display values stored by
     * CrewMovementCorrectionValueSnapshot use `Y-m-d H:i` (UTC-offset); we
     * reformat them to `d-m-Y H:i` for consistency with project date conventions.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $proposed
     * @return array<string, string>
     */
    private static function fieldChanges(array $original, array $proposed): array
    {
        $changes = [];

        foreach ($proposed as $field => $proposedEntry) {
            $label = self::FIELD_LABELS[(string) $field] ?? null;

            if ($label === null) {
                continue;
            }

            $newDisplay = self::extractDisplay($proposedEntry);
            $oldDisplay = self::extractDisplay($original[(string) $field] ?? null);

            if ($newDisplay === null && $oldDisplay === null) {
                continue;
            }

            if ($oldDisplay !== null && $newDisplay !== null) {
                $changes[$label] = self::reformatDate($oldDisplay).' → '.self::reformatDate($newDisplay);
            } elseif ($newDisplay !== null) {
                $changes[$label] = self::reformatDate($newDisplay);
            } elseif ($oldDisplay !== null) {
                $changes[$label] = self::reformatDate($oldDisplay).' → —';
            }
        }

        return $changes;
    }

    private static function extractDisplay(mixed $entry): ?string
    {
        if (is_array($entry) && array_key_exists('display', $entry)) {
            $display = $entry['display'];

            return (is_string($display) && trim($display) !== '') ? trim($display) : null;
        }

        if (is_string($entry) && trim($entry) !== '') {
            return trim($entry);
        }

        return null;
    }

    /**
     * Re-format stored snapshot display dates from `Y-m-d H:i` to `d-m-Y H:i`.
     * Non-date strings are returned unchanged.
     */
    private static function reformatDate(string $display): string
    {
        // Match "2026-06-07 11:17" or "2026-06-07 11:17:00" style
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $display)) {
            return $display;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i', substr($display, 0, 16))?->format('d-m-Y H:i') ?? $display;
        } catch (\Throwable) {
            return $display;
        }
    }

    /**
     * Minimal safe fallback when the correction record cannot be resolved.
     *
     * @return array<string, string>
     */
    private static function fallback(string $event): array
    {
        return ['Status' => match ($event) {
            'correction_requested' => 'Correction requested',
            'correction_approved' => 'Correction approved',
            'correction_rejected' => 'Correction rejected',
            'correction_cancelled' => 'Correction cancelled',
            default => 'Correction updated',
        }];
    }
}
