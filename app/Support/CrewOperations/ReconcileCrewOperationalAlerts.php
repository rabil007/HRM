<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertStatus;
use App\Enums\CrewOperationalAlertType;
use App\Models\CrewOperationalAlert;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reconciles persisted Crew operational alerts for one company.
 *
 * Creates missing alerts, refreshes last_detected_at / severity,
 * reactivates resolved alerts when the same condition returns,
 * and resolves alerts whose underlying condition no longer exists.
 *
 * One row per (company_id, dedupe_key); history lives in timestamps / status / activity log.
 */
final class ReconcileCrewOperationalAlerts
{
    public function __construct(
        private readonly DetectCrewOperationalAlerts $detector = new DetectCrewOperationalAlerts,
    ) {}

    /**
     * @return array{created: int, updated: int, resolved: int, skipped: bool}
     */
    public function forCompany(int $companyId): array
    {
        if (! CrewOperationsSettings::notificationsEnabled($companyId)) {
            return $this->resolveAllActive($companyId) + ['skipped' => true];
        }

        $enabledTypes = CrewOperationsSettings::enabledAlertTypes($companyId);

        if ($enabledTypes === []) {
            return $this->resolveAllActive($companyId) + ['skipped' => true];
        }

        $detected = $this->detector->forCompany($companyId, $enabledTypes);
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $now = CarbonImmutable::now($timezone);

        return DB::transaction(function () use ($companyId, $detected, $now): array {
            $created = 0;
            $updated = 0;
            $seenKeys = [];

            foreach ($detected as $item) {
                $seenKeys[] = $item['dedupe_key'];

                $existing = $this->findForUpdate($companyId, $item['dedupe_key']);

                if ($existing !== null) {
                    $this->applyDetectedCondition($existing, $item, $now);
                    $updated++;

                    continue;
                }

                try {
                    CrewOperationalAlert::query()->create([
                        'company_id' => $companyId,
                        'type' => $item['type'],
                        'severity' => $item['severity'],
                        'status' => CrewOperationalAlertStatus::Active,
                        'dedupe_key' => $item['dedupe_key'],
                        'title' => $item['title'],
                        'message' => $item['message'],
                        'context' => $item['context'],
                        'detected_at' => $now,
                        'last_detected_at' => $now,
                        'resolved_at' => null,
                    ]);
                    $created++;
                } catch (UniqueConstraintViolationException $exception) {
                    $existing = $this->findForUpdate($companyId, $item['dedupe_key']);

                    if ($existing === null) {
                        throw $exception;
                    }

                    $this->applyDetectedCondition($existing, $item, $now);
                    $updated++;
                }
            }

            $resolved = $this->resolveMissing($companyId, $seenKeys, $now);

            return [
                'created' => $created,
                'updated' => $updated,
                'resolved' => $resolved,
                'skipped' => false,
            ];
        });
    }

    /**
     * Best-effort company reconciliation that never throws to callers iterating many tenants.
     *
     * @return array{created: int, updated: int, resolved: int, skipped: bool, error?: string}
     */
    public function forCompanySafe(int $companyId): array
    {
        try {
            return $this->forCompany($companyId);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'created' => 0,
                'updated' => 0,
                'resolved' => 0,
                'skipped' => true,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{created: int, updated: int, resolved: int}
     */
    private function resolveAllActive(int $companyId): array
    {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $now = CarbonImmutable::now($timezone);

        return DB::transaction(function () use ($companyId, $now): array {
            return [
                'created' => 0,
                'updated' => 0,
                'resolved' => $this->resolveMissing($companyId, [], $now),
            ];
        });
    }

    private function findForUpdate(int $companyId, string $dedupeKey): ?CrewOperationalAlert
    {
        return CrewOperationalAlert::query()
            ->where('company_id', $companyId)
            ->where('dedupe_key', $dedupeKey)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array{
     *     type: CrewOperationalAlertType,
     *     severity: CrewOperationalAlertSeverity,
     *     dedupe_key: string,
     *     title: string,
     *     message: string,
     *     context: array<string, mixed>
     * }  $item
     */
    private function applyDetectedCondition(
        CrewOperationalAlert $alert,
        array $item,
        CarbonImmutable $now,
    ): void {
        $wasResolved = $alert->status === CrewOperationalAlertStatus::Resolved;

        if ($wasResolved) {
            $severity = $item['severity'];
        } else {
            $severity = $this->severityRank($item['severity']) > $this->severityRank($alert->severity)
                ? $item['severity']
                : $alert->severity;
        }

        $alert->fill([
            'type' => $item['type'],
            'severity' => $severity,
            'status' => CrewOperationalAlertStatus::Active,
            'title' => $item['title'],
            'message' => $item['message'],
            'context' => $item['context'],
            'last_detected_at' => $now,
            'resolved_at' => null,
        ]);
        // Preserve first-ever detected_at for auditable history.
        $alert->save();
    }

    /**
     * @param  list<string>  $seenKeys
     */
    private function resolveMissing(
        int $companyId,
        array $seenKeys,
        CarbonImmutable $now,
    ): int {
        $query = CrewOperationalAlert::query()
            ->where('company_id', $companyId)
            ->where('status', CrewOperationalAlertStatus::Active)
            ->lockForUpdate();

        if ($seenKeys !== []) {
            $query->whereNotIn('dedupe_key', $seenKeys);
        }

        $resolved = 0;

        foreach ($query->get() as $alert) {
            $alert->status = CrewOperationalAlertStatus::Resolved;
            $alert->resolved_at = $now;
            $alert->save();
            $resolved++;
        }

        return $resolved;
    }

    private function severityRank(CrewOperationalAlertSeverity $severity): int
    {
        return match ($severity) {
            CrewOperationalAlertSeverity::Info => 1,
            CrewOperationalAlertSeverity::Warning => 2,
            CrewOperationalAlertSeverity::Critical => 3,
        };
    }
}
