<?php

namespace App\Support\Queue;

use App\Support\EmployeeDocuments\DocumentExpiryAlertSchedule;
use App\Support\Hikvision\HikvisionAccessEventsFetchSchedule;
use App\Support\Hikvision\HikvisionEveningAccessEventsFetchSchedule;
use App\Support\Settings\ApplicationTimezone;

final class JobRegistry
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function entries(): array
    {
        $schedulerTimezone = ApplicationTimezone::identifier();
        $documentDispatchAt = DocumentExpiryAlertSchedule::dispatchAt();
        $hikvisionFetchAt = HikvisionAccessEventsFetchSchedule::dispatchAt();
        $hikvisionEveningFetchAt = HikvisionEveningAccessEventsFetchSchedule::dispatchAt();
        $leaveRolloverTimezone = (string) config('app.timezone', 'UTC');

        return [
            [
                'type' => 'job',
                'name' => 'FetchHikvisionAccessEventsJob',
                'class' => 'App\Jobs\FetchHikvisionAccessEventsJob',
                'purpose' => 'Fetches raw access log events from local Hikvision devices/APIs for a specific date (or scheduled runs) and stores them in the database.',
                'trigger' => 'Dispatched automatically by daily fetch commands or manually from the Hikvision Settings dashboard.',
                'queue' => 'default',
                'connection' => 'database',
                'parameters' => [
                    'date' => 'Optional date string (Y-m-d). If null, fetches scheduled access events for yesterday.',
                ],
                'details' => 'Timeout is 180s when date is passed, or 300s when null. It automatically dispatches SyncHikvisionAttendanceJob upon successful run.',
                'code_snippet' => "dispatch(new \\App\\Jobs\\FetchHikvisionAccessEventsJob('2026-06-25'));",
            ],
            [
                'type' => 'job',
                'name' => 'SyncHikvisionAttendanceJob',
                'class' => 'App\Jobs\SyncHikvisionAttendanceJob',
                'purpose' => 'Processes and synchronizes raw Hikvision access event logs against employee profiles, schedules, shifts, and rosters to generate accurate daily attendance logs.',
                'trigger' => 'Automatically dispatched after FetchHikvisionAccessEventsJob finishes, or manually from the attendance recalculation UI.',
                'queue' => 'default',
                'connection' => 'database',
                'parameters' => [
                    'date' => 'Optional date string (Y-m-d). If null, syncs all scheduled/pending days.',
                ],
                'details' => 'Timeout is 600s. Has a maximum attempts count of 1.',
                'code_snippet' => "dispatch(new \\App\\Jobs\\SyncHikvisionAttendanceJob('2026-06-25'));",
            ],
            [
                'type' => 'job',
                'name' => 'ProcessHikvisionWebhookEventJob',
                'class' => 'App\Jobs\ProcessHikvisionWebhookEventJob',
                'purpose' => 'Processes real-time webhook scan logs sent directly from Hikvision face-recognition terminals when an employee scans in/out.',
                'trigger' => 'Hikvision Webhook controller endpoint when a scan event is received.',
                'queue' => 'default',
                'connection' => 'database',
                'parameters' => [
                    'payload' => 'Array containing Hikvision webhook event details.',
                ],
                'details' => 'Upserts events into hikvision_access_events and triggers real-time attendance recalculation for the scanning employee.',
                'code_snippet' => 'dispatch(new \\App\\Jobs\\ProcessHikvisionWebhookEventJob($webhookPayload));',
            ],
            [
                'type' => 'job',
                'name' => 'GenerateBulkDocumentsJob',
                'class' => 'App\Jobs\GenerateBulkDocumentsJob',
                'purpose' => 'Renders bulk employee documents (salary declarations, certificates, etc.) and stores them as employee documents.',
                'trigger' => 'Manually from Organization → Documents → Bulk generate.',
                'queue' => 'default',
                'connection' => 'database',
                'parameters' => [
                    'companyId' => 'Integer company ID to generate documents for.',
                    'userId' => 'Integer user ID recorded as uploader.',
                    'documentTypeKey' => 'Registry key such as salary_declaration or salary_certificate.',
                    'filters' => 'Employee directory filter payload.',
                    'generationRunId' => 'BulkDocumentGenerationRun row to update with progress.',
                    'replaceExisting' => 'Whether to replace existing documents for selected employees.',
                ],
                'details' => 'Fill-gaps mode skips employees who already have the document. Selected-employee mode replaces existing PDFs. Processes employees in chunks and records progress on bulk_document_generation_runs.',
                'code_snippet' => 'GenerateBulkDocumentsJob::dispatch($companyId, $userId, $documentTypeKey, $filters, $runId, $replaceExisting, $employeeIds);',
            ],
            [
                'type' => 'job',
                'name' => 'RegenerateAlignedSignedBulkDocumentPdfsJob',
                'class' => 'App\Jobs\RegenerateAlignedSignedBulkDocumentPdfsJob',
                'purpose' => 'Re-renders selected signed salary declaration PDFs via HTML template for correct signature alignment.',
                'trigger' => 'Manually from Organization → Documents → Bulk → Signatures (Regenerate alignment).',
                'queue' => 'default',
                'connection' => 'database',
                'parameters' => [
                    'companyId' => 'Integer company ID.',
                    'userId' => 'Integer user ID who initiated the repair.',
                    'repairRunId' => 'BulkDocumentSignatureRepairRun row to update with progress.',
                    'requestIds' => 'List of BulkDocumentSignatureRequest IDs to repair.',
                ],
                'details' => 'Forces template render (Browsershot) instead of FPDI stamp. Does not change the public e-sign submit path. Processes requests in chunks.',
                'code_snippet' => 'RegenerateAlignedSignedBulkDocumentPdfsJob::dispatch($companyId, $userId, $runId, $requestIds);',
            ],
            [
                'type' => 'job',
                'name' => 'SendDocumentExpiryAlertJob',
                'class' => 'App\Jobs\SendDocumentExpiryAlertJob',
                'purpose' => 'Checks for expiring employee/company documents (visas, passports, licenses) and sends email alerts to HR managers.',
                'trigger' => 'Daily console command (documents:dispatch-expiry-alerts).',
                'queue' => 'default',
                'connection' => 'database',
                'parameters' => [
                    'companyId' => 'Integer company ID to scan documents for.',
                ],
                'details' => 'Tries up to 3 times with progressive backoff: 60s, 300s, 900s. Logs failures to document_alert_logs.',
                'code_snippet' => 'dispatch(new \\App\\Jobs\\SendDocumentExpiryAlertJob($companyId));',
            ],
            [
                'type' => 'command',
                'name' => 'documents:dispatch-expiry-alerts',
                'class' => 'App\Console\Commands\DispatchDocumentExpiryAlertsCommand',
                'purpose' => 'Scans all active companies and dispatches visa/passport/document expiry notification jobs for each.',
                'trigger' => "Artisan Schedule (daily). Runs at {$documentDispatchAt} ({$schedulerTimezone}).",
                'schedule' => self::dailyCronLabel($documentDispatchAt, $schedulerTimezone),
                'signature' => 'documents:dispatch-expiry-alerts {--company= : Limit to a single company ID}',
                'details' => 'Runs daily without overlapping to prevent duplicate emails.',
                'code_snippet' => 'php artisan documents:dispatch-expiry-alerts --company=1',
            ],
            [
                'type' => 'command',
                'name' => 'leave-balances:rollover',
                'class' => 'App\Console\Commands\RolloverLeaveBalancesCommand',
                'purpose' => 'Processes annual rollover of employee leave balances, resetting annual leaves and applying carryover rules.',
                'trigger' => 'Artisan Schedule (yearly). Runs on Jan 1st.',
                'schedule' => "30 0 1 1 * (Jan 1st at 00:30 {$leaveRolloverTimezone})",
                'signature' => 'leave-balances:rollover',
                'details' => 'Clears existing allocations and applies new annual accruals based on policy settings.',
                'code_snippet' => 'php artisan leave-balances:rollover',
            ],
            [
                'type' => 'command',
                'name' => 'hikvision:fetch-access-events',
                'class' => 'App\Console\Commands\FetchHikvisionAccessEventsCommand',
                'purpose' => 'Fetches the previous day\'s access log events from the local Hikvision device API.',
                'trigger' => "Artisan Schedule (daily). Runs at {$hikvisionFetchAt} ({$schedulerTimezone}).",
                'schedule' => self::dailyCronLabel($hikvisionFetchAt, $schedulerTimezone),
                'signature' => 'hikvision:fetch-access-events',
                'details' => 'Only runs if enabled in Hikvision settings. Dispatches FetchHikvisionAccessEventsJob.',
                'code_snippet' => 'php artisan hikvision:fetch-access-events',
            ],
            [
                'type' => 'command',
                'name' => 'hikvision:fetch-todays-access-events',
                'class' => 'App\Console\Commands\FetchTodaysHikvisionAccessEventsCommand',
                'purpose' => 'Fetches the current day\'s (evening) access log events from local Hikvision devices.',
                'trigger' => "Artisan Schedule (daily). Runs at {$hikvisionEveningFetchAt} ({$schedulerTimezone}).",
                'schedule' => self::dailyCronLabel($hikvisionEveningFetchAt, $schedulerTimezone),
                'signature' => 'hikvision:fetch-todays-access-events',
                'details' => 'Ensures recent evening punches are captured promptly for rosters. Only runs if enabled.',
                'code_snippet' => 'php artisan hikvision:fetch-todays-access-events',
            ],
            [
                'type' => 'command',
                'name' => 'leave-balances:sync',
                'class' => 'App\Console\Commands\SyncLeaveBalancesCommand',
                'purpose' => 'Synchronizes employee leave balances and allocations based on approved requests.',
                'trigger' => 'Console / Manual run.',
                'schedule' => 'Manual run',
                'signature' => 'leave-balances:sync',
                'details' => 'Re-calculates leave allocations against taken leaves to fix any balance mismatches.',
                'code_snippet' => 'php artisan leave-balances:sync',
            ],
        ];
    }

    private static function dailyCronLabel(string $time, string $timezone): string
    {
        [$hour, $minute] = array_pad(explode(':', $time, 2), 2, '00');

        return sprintf(
            '%s %s * * * (Daily at %s %s)',
            (int) $minute,
            (int) $hour,
            $time,
            $timezone,
        );
    }
}
