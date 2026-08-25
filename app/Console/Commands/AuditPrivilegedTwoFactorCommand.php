<?php

namespace App\Console\Commands;

use App\Support\Auth\PrivilegedTwoFactorEnrollmentAudit;
use App\Support\Auth\PrivilegedTwoFactorPolicy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('security:audit-privileged-2fa')]
#[Description('Report active privileged or platform users who have not confirmed Fortify 2FA (read-only)')]
class AuditPrivilegedTwoFactorCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PrivilegedTwoFactorEnrollmentAudit $audit): int
    {
        $unenrolled = $audit->unenrolledActiveUsers();
        $enforced = PrivilegedTwoFactorPolicy::isEnforced();
        $inactiveCount = $audit->inactivePrivilegedUserCount();
        $enforcementLabel = $enforced ? 'ON' : 'OFF';

        if ($unenrolled === []) {
            $this->info('All active privileged and platform users have confirmed Fortify 2FA. Rollout is ready.');
            $this->line("Enforcement is currently {$enforcementLabel} (PRIVILEGED_2FA_ENFORCED).");
            $this->reportOmittedInactiveUsers($inactiveCount);

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Found %d active privileged or platform user(s) without confirmed two-factor authentication.',
            count($unenrolled),
        ));

        foreach ($unenrolled as $row) {
            $this->line(sprintf(
                'user_id=%d email=%s enrollment=%s platform_access=%s capabilities=%s',
                $row['id'],
                $row['email'],
                $row['enrollment'],
                $row['platform_access'] ?? '-',
                implode(',', $row['capabilities']),
            ));
        }

        $this->newLine();
        $this->line("Enforcement is currently {$enforcementLabel} (PRIVILEGED_2FA_ENFORCED).");
        $this->line('Enroll these users on Security settings before setting PRIVILEGED_2FA_ENFORCED=true.');
        $this->line('This command is read-only. Secrets and recovery codes are never printed.');
        $this->reportOmittedInactiveUsers($inactiveCount);

        return self::FAILURE;
    }

    private function reportOmittedInactiveUsers(int $inactiveCount): void
    {
        if ($inactiveCount === 0) {
            return;
        }

        $this->comment(sprintf(
            '%d inactive privileged or platform user(s) omitted; they cannot authenticate until status is active.',
            $inactiveCount,
        ));
    }
}
