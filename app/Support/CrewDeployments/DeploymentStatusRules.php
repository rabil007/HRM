<?php

namespace App\Support\CrewDeployments;

final class DeploymentStatusRules
{
    /**
     * @return array{
     *     intro: string,
     *     priority_note: string,
     *     statuses: list<array{
     *         status: string,
     *         label: string,
     *         summary: string,
     *         conditions: list<string>,
     *         badge: string|null
     *     }>,
     *     date_highlights: array{
     *         title: string,
     *         overdue: string,
     *         fields: list<string>
     *     },
     *     needs_update_hints: list<string>,
     *     in_home: array{title: string, summary: string, conditions: list<string>}
     * }
     */
    public static function forPage(): array
    {
        return [
            'intro' => 'The "Where now" column and summary cards are calculated automatically from each deployment\'s dates. You do not pick a status manually — update the dates and the status updates on its own.',
            'priority_note' => 'Statuses are checked in the order below. The first rule that matches wins.',
            'statuses' => [
                [
                    'status' => DeploymentStatus::ON_VESSEL,
                    'label' => 'On vessel',
                    'summary' => 'Crew member is currently serving on the assigned vessel.',
                    'conditions' => [
                        'Joined date is today or earlier.',
                        'Disembarked date is empty, or is after today.',
                    ],
                    'badge' => 'Shows the vessel name, e.g. "On L Etoile".',
                ],
                [
                    'status' => DeploymentStatus::JOIN_STANDBY,
                    'label' => 'Join standby',
                    'summary' => 'Crew member is on standby before joining the vessel.',
                    'conditions' => [
                        'Not currently on vessel.',
                        'Join standby from date is today or earlier.',
                        'Join standby to date is empty, or today is on or before that date.',
                        'Joined date is empty, or is after today.',
                    ],
                    'badge' => null,
                ],
                [
                    'status' => DeploymentStatus::LEAVE_STANDBY,
                    'label' => 'Leave standby',
                    'summary' => 'Crew member has disembarked and is on standby before travelling home.',
                    'conditions' => [
                        'Disembarked date is today or earlier.',
                        'Leave standby from date is today or earlier.',
                        'Leave standby to date is empty, or today is on or before that date.',
                        'Travel date is empty, or is after today.',
                    ],
                    'badge' => null,
                ],
                [
                    'status' => DeploymentStatus::TRAVEL,
                    'label' => 'Travel',
                    'summary' => 'Crew member has left the vessel and is travelling (or has travelled).',
                    'conditions' => [
                        'Disembarked date is today or earlier.',
                        'Travel date is recorded.',
                    ],
                    'badge' => 'Shown as "Travelled".',
                ],
                [
                    'status' => DeploymentStatus::ARRIVED,
                    'label' => 'Arrived',
                    'summary' => 'Crew member has arrived at the vessel location but has not joined yet.',
                    'conditions' => [
                        'Arrived date is recorded.',
                        'Joined date is empty.',
                        'Arrived date is today or in the future, or join standby has not started yet.',
                    ],
                    'badge' => null,
                ],
                [
                    'status' => DeploymentStatus::UNKNOWN,
                    'label' => 'Needs update',
                    'summary' => 'Dates are missing or overdue — the record needs attention.',
                    'conditions' => [
                        'Disembarked in the past with no travel date and not in leave standby.',
                        'Arrived in the past with no join date.',
                        'Join standby ended with no join date.',
                        'Leave standby ended with no travel date.',
                        'Any other incomplete date combination.',
                    ],
                    'badge' => 'Hover the badge for a hint on what date to add next.',
                ],
                [
                    'status' => DeploymentStatus::DISEMBARKED,
                    'label' => 'Disembarked',
                    'summary' => 'Crew member left the vessel today and the next step is not recorded yet.',
                    'conditions' => [
                        'Disembarked date is today.',
                        'Travel date is empty.',
                    ],
                    'badge' => 'Only applies on the disembark day itself. After that, add leave standby or travel.',
                ],
            ],
            'date_highlights' => [
                'title' => 'Date highlights in the table',
                'overdue' => 'Red — the date has passed and the next step in the lifecycle is missing.',
                'fields' => [
                    'Join standby to — while in join standby, if join date is still missing.',
                    'Disembarked — while on vessel with a planned disembark, or on the disembark day.',
                    'Leave standby to — while in leave standby, if travel date is still missing.',
                    'Arrived — when arrival has passed without a join date.',
                ],
            ],
            'needs_update_hints' => [
                'Arrived Xd ago — add join date',
                'Join standby ended Xd ago — add join date',
                'Disembarked Xd ago — add travel or standby',
                'Leave standby ended Xd ago — add travel date',
                'Dates incomplete — review record',
            ],
            'in_home' => [
                'title' => 'In home',
                'summary' => 'Shown when the employee\'s latest deployment is in travel status and they have a travel date on or before today.',
                'conditions' => [
                    'Uses only the employee\'s most recent deployment record.',
                    'Travel date is today or earlier.',
                    'Underlying status resolves to Travel.',
                ],
            ],
        ];
    }
}
