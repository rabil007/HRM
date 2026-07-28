<?php

namespace App\Jobs;

use App\Enums\DocumentExpiryPushAlertStatus;
use App\Models\Company;
use App\Models\DocumentExpiryPushAlert;
use App\Models\User;
use App\Notifications\DocumentComplianceWebPushNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class DeliverDocumentComplianceWebPushJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * @param  list<int>  $pushAlertIds
     */
    public function __construct(
        public int $companyId,
        public int $userId,
        public array $pushAlertIds,
    ) {}

    public function uniqueId(): string
    {
        $ids = $this->pushAlertIds;
        sort($ids);

        return 'document-compliance-web-push-'.$this->companyId.'-'.$this->userId.'-'.md5(implode(',', $ids));
    }

    public function handle(): void
    {
        $company = Company::query()
            ->whereKey($this->companyId)
            ->where('status', 'active')
            ->first();

        if ($company === null) {
            $this->markAlertsFailed('company_unavailable');

            return;
        }

        $user = User::query()
            ->whereKey($this->userId)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'active');
            })
            ->first();

        if ($user === null) {
            $this->markAlertsFailed('user_unavailable');

            return;
        }

        $hasActiveMembership = $user->companies()
            ->whereKey($company->id)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $hasActiveMembership) {
            $this->markAlertsFailed('membership_unavailable');

            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($company->id);

            if (! $user->can('documents.view')) {
                $this->markAlertsFailed('permission_unavailable');

                return;
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        if ($user->pushSubscriptions()->doesntExist()) {
            $this->markAlertsFailed('subscriptions_unavailable');

            return;
        }

        $alertIds = DB::transaction(function () use ($company, $user): array {
            $alerts = DocumentExpiryPushAlert::query()
                ->whereKey($this->pushAlertIds)
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->where('status', DocumentExpiryPushAlertStatus::Queued)
                ->lockForUpdate()
                ->get();

            return $alerts
                ->filter(function (DocumentExpiryPushAlert $alert) use ($company): bool {
                    return $alert->employeeDocument()
                        ->where('company_id', $company->id)
                        ->exists();
                })
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
        });

        if ($alertIds === []) {
            return;
        }

        try {
            Notification::send($user, new DocumentComplianceWebPushNotification($company->id));

            DocumentExpiryPushAlert::query()
                ->whereKey($alertIds)
                ->where('status', DocumentExpiryPushAlertStatus::Queued)
                ->update([
                    'status' => DocumentExpiryPushAlertStatus::Sent,
                    'sent_at' => now(),
                    'failed_at' => null,
                    'failure_category' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            Log::warning('Document compliance web push delivery failed', [
                'company_id' => $this->companyId,
                'user_id' => $this->userId,
                'attempt' => $this->attempts(),
                'notification_type' => 'document_compliance_web_push',
                'exception_class' => $exception::class,
                'failure_category' => 'web_push_transport',
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->markAlertsFailed('web_push_exhausted');

        Log::warning('Document compliance web push delivery exhausted retries', [
            'company_id' => $this->companyId,
            'user_id' => $this->userId,
            'attempt' => $this->attempts(),
            'notification_type' => 'document_compliance_web_push',
            'exception_class' => $exception::class,
            'failure_category' => 'web_push_exhausted',
        ]);
    }

    private function markAlertsFailed(string $category): void
    {
        DocumentExpiryPushAlert::query()
            ->whereKey($this->pushAlertIds)
            ->where('company_id', $this->companyId)
            ->where('user_id', $this->userId)
            ->where('status', DocumentExpiryPushAlertStatus::Queued)
            ->update([
                'status' => DocumentExpiryPushAlertStatus::Failed,
                'failed_at' => now(),
                'failure_category' => $category,
                'updated_at' => now(),
            ]);
    }
}
