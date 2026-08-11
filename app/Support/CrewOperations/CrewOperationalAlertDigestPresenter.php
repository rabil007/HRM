<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertType;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertEmailDelivery;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

final class CrewOperationalAlertDigestPresenter
{
    public function __construct(
        private readonly ResolveCrewOperationalAlertUrl $resolveUrl = new ResolveCrewOperationalAlertUrl,
    ) {}

    /**
     * @param  Collection<int, CrewOperationalAlertEmailDelivery>  $deliveries
     * @return array{
     *     alert_count: int,
     *     highest_severity: string,
     *     alerts_table: string
     * }
     */
    public function forUser(
        User $user,
        Company $company,
        Collection $deliveries,
    ): array {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId((int) $company->id);

            $canViewAssignments = (bool) $user->can('crew_operations.assignments.view');
            $canViewOverview = (bool) $user->can('crew_operations.overview.view');
            $canViewManning = (bool) $user->can('crew_operations.vessel_manning.view');
            $canViewPlanning = (bool) $user->can('crew_operations.planning.view');

            $rowsHtml = [];
            $severities = [];

            foreach ($deliveries as $delivery) {
                $alert = $delivery->alert;
                if ($alert === null) {
                    continue;
                }

                $severities[] = $alert->severity;
                $url = $this->resolveUrl->forUser($user, $alert);

                $rowsHtml[] = $this->renderAlertRow(
                    $alert,
                    $url,
                    canViewAssignments: $canViewAssignments,
                    canViewOverview: $canViewOverview,
                    canViewManning: $canViewManning,
                    canViewPlanning: $canViewPlanning,
                );
            }

            $highestSeverity = $this->highestSeverity($severities);
            $tableHtml = $this->buildTableMarkup($rowsHtml);

            return [
                'alert_count' => count($rowsHtml),
                'highest_severity' => $highestSeverity->value,
                'alerts_table' => $tableHtml,
            ];
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    /**
     * Renders sample rows for Email Template Preview.
     */
    public static function sampleTable(): string
    {
        $samples = [
            [
                'severity_label' => 'CRITICAL',
                'severity_bg' => '#fee2e2',
                'severity_color' => '#991b1b',
                'title' => 'Sign-off overdue',
                'type_label' => 'SignoffOverdue',
                'details_html' => '<div style="font-weight:600;color:#18181b;">Muhammad Arfah (EMP-1042)</div><div style="color:#52525b;font-size:12px;">Sea Eagle · 2nd Officer</div><div style="color:#dc2626;font-size:12px;margin-top:2px;">1 day overdue (Planned: 09 Aug 2026)</div>',
                'action_url' => url('/organization/crew/1'),
            ],
            [
                'severity_label' => 'WARNING',
                'severity_bg' => '#fef3c7',
                'severity_color' => '#92400e',
                'title' => 'Sign-off approaching — no relief',
                'type_label' => 'SignoffNoRelief',
                'details_html' => '<div style="font-weight:600;color:#18181b;">Ahmed Khan (EMP-0871)</div><div style="color:#52525b;font-size:12px;">Ocean Star · Able Seaman</div><div style="color:#d97706;font-size:12px;margin-top:2px;">Sign-off in 4 days (No relief planned)</div>',
                'action_url' => url('/organization/crew/2'),
            ],
            [
                'severity_label' => 'CRITICAL',
                'severity_bg' => '#fee2e2',
                'severity_color' => '#991b1b',
                'title' => 'Current manning gap',
                'type_label' => 'CurrentManningGap',
                'details_html' => '<div style="font-weight:600;color:#18181b;">Sea Eagle · 2nd Officer</div><div style="color:#dc2626;font-size:12px;">Short 1 (1 of 2 onboard)</div>',
                'action_url' => url('/organization/vessels/1'),
            ],
            [
                'severity_label' => 'WARNING',
                'severity_bg' => '#fef3c7',
                'severity_color' => '#92400e',
                'title' => 'Projected manning gap',
                'type_label' => 'ProjectedManningGap',
                'details_html' => '<div style="font-weight:600;color:#18181b;">Ocean Star · Able Seaman</div><div style="color:#d97706;font-size:12px;">Max shortage 1 (Gap from 17 Aug)</div>',
                'action_url' => url('/organization/crew-planning'),
            ],
        ];

        $rows = [];
        foreach ($samples as $sample) {
            $rows[] = sprintf(
                '<tr style="border-bottom:1px solid #e4e4e7;">
                    <td style="padding:12px;vertical-align:top;width:90px;">
                        <span style="display:inline-block;padding:2px 8px;font-size:11px;font-weight:700;border-radius:4px;background-color:%s;color:%s;">%s</span>
                    </td>
                    <td style="padding:12px;vertical-align:top;width:180px;">
                        <strong style="color:#18181b;display:block;font-size:13px;">%s</strong>
                        <span style="color:#71717a;font-size:11px;">%s</span>
                    </td>
                    <td style="padding:12px;vertical-align:top;">%s</td>
                    <td style="padding:12px;vertical-align:top;text-align:right;width:70px;">
                        <a href="%s" style="display:inline-block;padding:6px 12px;font-size:12px;font-weight:600;color:#2563eb;text-decoration:none;border:1px solid #bfdbfe;border-radius:6px;background-color:#eff6ff;">View</a>
                    </td>
                </tr>',
                $sample['severity_bg'],
                $sample['severity_color'],
                $sample['severity_label'],
                e($sample['title']),
                e($sample['type_label']),
                $sample['details_html'],
                e($sample['action_url']),
            );
        }

        return (new self)->buildTableMarkup($rows);
    }

    private function renderAlertRow(
        CrewOperationalAlert $alert,
        ?string $actionUrl,
        bool $canViewAssignments,
        bool $canViewOverview,
        bool $canViewManning,
        bool $canViewPlanning,
    ): string {
        $severityBadge = $this->severityBadgeMarkup($alert->severity);
        $title = e($alert->title);
        $typeLabel = e($alert->type->value);

        $hasDetailPermission = match ($alert->type) {
            CrewOperationalAlertType::SignoffOverdue,
            CrewOperationalAlertType::SignoffNoRelief,
            CrewOperationalAlertType::ReliefNotReady => $canViewAssignments,
            CrewOperationalAlertType::CurrentManningGap => $canViewManning || $canViewOverview,
            CrewOperationalAlertType::ProjectedManningGap => $canViewPlanning || $canViewManning || $canViewOverview,
        };

        if ($hasDetailPermission) {
            $detailsHtml = $this->buildAuthorizedDetails($alert);
        } else {
            $detailsHtml = '<div style="color:#71717a;font-size:12px;">A Crew Operations item requires review.</div>';
        }

        $actionHtml = '';
        if ($actionUrl !== null && $actionUrl !== '') {
            $actionHtml = sprintf(
                '<a href="%s" style="display:inline-block;padding:6px 12px;font-size:12px;font-weight:600;color:#2563eb;text-decoration:none;border:1px solid #bfdbfe;border-radius:6px;background-color:#eff6ff;">View</a>',
                e($actionUrl),
            );
        }

        return sprintf(
            '<tr style="border-bottom:1px solid #e4e4e7;">
                <td style="padding:12px;vertical-align:top;width:90px;">%s</td>
                <td style="padding:12px;vertical-align:top;width:180px;">
                    <strong style="color:#18181b;display:block;font-size:13px;">%s</strong>
                    <span style="color:#71717a;font-size:11px;">%s</span>
                </td>
                <td style="padding:12px;vertical-align:top;">%s</td>
                <td style="padding:12px;vertical-align:top;text-align:right;width:70px;">%s</td>
            </tr>',
            $severityBadge,
            $title,
            $typeLabel,
            $detailsHtml,
            $actionHtml,
        );
    }

    private function buildAuthorizedDetails(CrewOperationalAlert $alert): string
    {
        $context = $alert->context ?? [];
        $assignmentId = $context['assignment_id'] ?? null;

        if (is_numeric($assignmentId)) {
            $assignment = CrewAssignment::query()
                ->with(['employee:id,name,employee_no', 'vessel:id,name', 'rank:id,name'])
                ->find((int) $assignmentId);

            if ($assignment !== null) {
                $employeeName = $assignment->employee?->name ?? 'Crew member';
                $employeeNo = $assignment->employee?->employee_no;
                $vesselName = $assignment->vessel?->name ?? 'Unassigned vessel';
                $rankName = $assignment->rank?->name ?? 'Unassigned rank';
                $plannedSignoff = $assignment->planned_signoff_at?->toDateString();

                $crewLine = e($employeeName);
                if (filled($employeeNo)) {
                    $crewLine .= ' ('.e($employeeNo).')';
                }

                $vesselRankLine = e($vesselName).' · '.e($rankName);
                $statusLine = '';

                if ($alert->type === CrewOperationalAlertType::SignoffOverdue) {
                    $statusLine = '<div style="color:#dc2626;font-size:12px;margin-top:2px;">Past planned sign-off'.($plannedSignoff ? ' ('.$plannedSignoff.')' : '').'</div>';
                } elseif ($alert->type === CrewOperationalAlertType::SignoffNoRelief) {
                    $days = $context['days_until_signoff'] ?? null;
                    $daysText = is_numeric($days) ? "in {$days} days" : 'approaching';
                    $statusLine = "<div style=\"color:#d97706;font-size:12px;margin-top:2px;\">Sign-off {$daysText} (No relief planned)</div>";
                } elseif ($alert->type === CrewOperationalAlertType::ReliefNotReady) {
                    $days = $context['days_until_signoff'] ?? null;
                    $daysText = is_numeric($days) ? "in {$days} days" : 'approaching';
                    $status = $context['relief_status'] ?? 'Not ready';
                    $statusLine = "<div style=\"color:#d97706;font-size:12px;margin-top:2px;\">Sign-off {$daysText} (Relief: ".e($status).')</div>';
                }

                return sprintf(
                    '<div style="font-weight:600;color:#18181b;">%s</div>
                    <div style="color:#52525b;font-size:12px;">%s</div>
                    %s',
                    $crewLine,
                    $vesselRankLine,
                    $statusLine,
                );
            }
        }

        if ($alert->type === CrewOperationalAlertType::CurrentManningGap) {
            $vessel = e($context['vessel_name'] ?? 'Vessel');
            $rank = e($context['rank_name'] ?? 'Rank');
            $gap = (int) ($context['gap'] ?? 1);
            $actual = (int) ($context['actual_count'] ?? 0);
            $req = (int) ($context['required_count'] ?? 1);

            return sprintf(
                '<div style="font-weight:600;color:#18181b;">%s · %s</div>
                <div style="color:#dc2626;font-size:12px;margin-top:2px;">Short %d (%d of %d onboard)</div>',
                $vessel,
                $rank,
                $gap,
                $actual,
                $req,
            );
        }

        if ($alert->type === CrewOperationalAlertType::ProjectedManningGap) {
            $vessel = e($context['vessel_name'] ?? 'Vessel');
            $rank = e($context['rank_name'] ?? 'Rank');
            $maxGap = (int) ($context['maximum_gap'] ?? 1);
            $gapDate = $context['next_gap_date'] ?? null;

            $dateText = filled($gapDate) ? ' (Gap from '.e($gapDate).')' : '';

            return sprintf(
                '<div style="font-weight:600;color:#18181b;">%s · %s</div>
                <div style="color:#d97706;font-size:12px;margin-top:2px;">Max shortage %d%s</div>',
                $vessel,
                $rank,
                $maxGap,
                $dateText,
            );
        }

        return '<div style="color:#3f3f46;font-size:12px;">'.e($alert->message).'</div>';
    }

    private function severityBadgeMarkup(CrewOperationalAlertSeverity $severity): string
    {
        [$bg, $color, $label] = match ($severity) {
            CrewOperationalAlertSeverity::Critical => ['#fee2e2', '#991b1b', 'CRITICAL'],
            CrewOperationalAlertSeverity::Warning => ['#fef3c7', '#92400e', 'WARNING'],
            CrewOperationalAlertSeverity::Info => ['#dbeafe', '#1e40af', 'INFO'],
        };

        return sprintf(
            '<span style="display:inline-block;padding:2px 8px;font-size:11px;font-weight:700;border-radius:4px;background-color:%s;color:%s;">%s</span>',
            $bg,
            $color,
            $label,
        );
    }

    /**
     * @param  list<string>  $rowsHtml
     */
    private function buildTableMarkup(array $rowsHtml): string
    {
        if ($rowsHtml === []) {
            return '<p style="color:#71717a;font-size:13px;">No items require attention.</p>';
        }

        return sprintf(
            '<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%%;font-size:13px;border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;margin:16px 0;">
                <thead>
                    <tr style="background-color:#f4f4f5;border-bottom:1px solid #e4e4e7;text-align:left;">
                        <th style="padding:10px 12px;font-size:11px;font-weight:600;text-transform:uppercase;color:#52525b;width:90px;">Severity</th>
                        <th style="padding:10px 12px;font-size:11px;font-weight:600;text-transform:uppercase;color:#52525b;width:180px;">Alert</th>
                        <th style="padding:10px 12px;font-size:11px;font-weight:600;text-transform:uppercase;color:#52525b;">Details</th>
                        <th style="padding:10px 12px;font-size:11px;font-weight:600;text-transform:uppercase;color:#52525b;text-align:right;width:70px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    %s
                </tbody>
            </table>',
            implode('', $rowsHtml),
        );
    }

    /**
     * @param  list<CrewOperationalAlertSeverity>  $severities
     */
    private function highestSeverity(array $severities): CrewOperationalAlertSeverity
    {
        if (in_array(CrewOperationalAlertSeverity::Critical, $severities, true)) {
            return CrewOperationalAlertSeverity::Critical;
        }

        if (in_array(CrewOperationalAlertSeverity::Warning, $severities, true)) {
            return CrewOperationalAlertSeverity::Warning;
        }

        return CrewOperationalAlertSeverity::Info;
    }
}
