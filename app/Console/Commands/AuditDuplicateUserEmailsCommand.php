<?php

namespace App\Console\Commands;

use App\Support\Auth\UserEmailIdentity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:audit-duplicate-emails {--show-emails : Print full email addresses instead of masked values}')]
#[Description('Report non-deleted User rows that share a login email (read-only; does not repair data)')]
class AuditDuplicateUserEmailsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(UserEmailIdentity $identity): int
    {
        $groups = $identity->duplicateGroups();

        if ($groups === []) {
            $this->info('No duplicate User email identities found.');

            return self::SUCCESS;
        }

        $userCount = array_sum(array_map(fn (array $group): int => $group['identity_count'], $groups));
        $showEmails = (bool) $this->option('show-emails');

        $this->error(sprintf(
            'Found %d duplicate email identit%s across %d User rows.',
            count($groups),
            count($groups) === 1 ? 'y' : 'ies',
            $userCount,
        ));

        $rows = [];

        foreach ($groups as $group) {
            foreach ($group['users'] as $user) {
                $membershipSummary = collect($user['memberships'])
                    ->map(function (array $membership): string {
                        $status = $membership['status'] ?? 'unknown';

                        return $membership['company_id'].':'.$status;
                    })
                    ->implode(', ');

                $rows[] = [
                    $showEmails ? $user['email'] : UserEmailIdentity::mask($user['email']),
                    $user['id'],
                    $user['status'] ?? '',
                    $user['home_company_id'] ?? '',
                    $user['membership_count'],
                    $membershipSummary,
                    $user['employee_link_count'],
                    $user['role_assignment_count'],
                ];
            }
        }

        $this->table(
            ['email', 'user_id', 'status', 'home_company_id', 'memberships', 'membership_detail', 'employee_links', 'role_assignments'],
            $rows,
        );

        $this->newLine();
        $this->warn('These User rows are an invalid/ambiguous login identity.');
        $this->line('Authentication and password reset fail closed until each email maps to exactly one non-deleted User.');
        $this->line('Do not merge, delete, or move memberships/roles/employee links automatically.');
        $this->line('Review each group, keep one User as the global login identity, grant other companies through memberships, then add a global unique index in a later change.');

        return self::FAILURE;
    }
}
