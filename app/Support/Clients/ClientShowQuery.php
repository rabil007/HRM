<?php

namespace App\Support\Clients;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Activity\RecentActivityQuery;
use App\Support\MasterData\MasterDataUsage;

final class ClientShowQuery
{
    /**
     * @return array{
     *     client: array{
     *         id: int,
     *         name: string,
     *         is_active: bool,
     *         created_at: string|null,
     *         updated_at: string|null,
     *         is_in_use: bool,
     *         can_delete: bool,
     *         usage_count: null,
     *         usage_label: null
     *     },
     *     operations: array{
     *         projects: array{
     *             total_count: int,
     *             active_count: int,
     *             preview: list<array{
     *                 id: int,
     *                 title: string,
     *                 is_active: bool,
     *                 created_at: string|null
     *             }>
     *         }|null,
     *         vessels: array{
     *             total_count: int,
     *             active_count: int,
     *             preview: list<array{
     *                 id: int,
     *                 name: string,
     *                 vessel_type_id: int|null,
     *                 vessel_type_name: string|null,
     *                 is_active: bool,
     *                 imo_no: string|null,
     *                 call_sign: string|null,
     *                 official_no: string|null,
     *                 created_at: string|null
     *             }>
     *         }|null
     *     },
     *     can: array{
     *         update: bool,
     *         delete: bool,
     *         view_projects: bool,
     *         create_project: bool,
     *         view_vessels: bool,
     *         create_vessel: bool,
     *         view_audit: bool
     *     },
     *     recent_activity: list<array<string, mixed>>,
     *     can_view_audit: bool
     * }
     */
    public static function get(Client $client, int $companyId, ?User $user): array
    {
        $canDeletePermission = (bool) ($user?->can('settings.master-data.clients.delete'));
        $canViewProjects = (bool) ($user?->can('settings.master-data.projects.view'));
        $canViewVessels = (bool) ($user?->can('crew_operations.vessels.view'));
        $canViewAudit = (bool) ($user?->can('audit.view'));

        $summary = MasterDataUsage::summary($client, $companyId > 0 ? $companyId : null);
        $usageFlags = $summary->flags($canDeletePermission, $companyId > 0 ? $companyId : null);

        // Projects are global master data linked to this client
        $projectsData = null;
        if ($canViewProjects) {
            $projectBaseQuery = $client->projects();
            $totalProjectsCount = (int) (clone $projectBaseQuery)->count();
            $activeProjectsCount = (int) (clone $projectBaseQuery)->where('is_active', true)->count();

            $projectPreview = (clone $projectBaseQuery)
                ->orderBy('title')
                ->limit(5)
                ->get(['id', 'client_id', 'title', 'is_active', 'created_at'])
                ->map(fn (Project $project): array => [
                    'id' => (int) $project->id,
                    'title' => (string) $project->title,
                    'is_active' => (bool) $project->is_active,
                    'created_at' => $project->created_at?->toIso8601String(),
                ])
                ->values()
                ->all();

            $projectsData = [
                'total_count' => $totalProjectsCount,
                'active_count' => $activeProjectsCount,
                'preview' => $projectPreview,
            ];
        }

        // Vessels are company-scoped; strictly filter by the active company_id
        $vesselsData = null;
        if ($canViewVessels) {
            $vesselBaseQuery = $companyId > 0
                ? $client->vessels()->where('company_id', $companyId)
                : $client->vessels()->whereRaw('1 = 0');

            $totalVesselsCount = (int) (clone $vesselBaseQuery)->count();
            $activeVesselsCount = (int) (clone $vesselBaseQuery)->where('is_active', true)->count();

            $vesselPreview = (clone $vesselBaseQuery)
                ->with('vesselType:id,name')
                ->orderBy('name')
                ->limit(5)
                ->get([
                    'id',
                    'company_id',
                    'client_id',
                    'name',
                    'vessel_type_id',
                    'imo_no',
                    'call_sign',
                    'official_no',
                    'is_active',
                    'created_at',
                ])
                ->map(fn (Vessel $vessel): array => [
                    'id' => (int) $vessel->id,
                    'name' => (string) $vessel->name,
                    'vessel_type_id' => $vessel->vessel_type_id !== null ? (int) $vessel->vessel_type_id : null,
                    'vessel_type_name' => $vessel->vesselType?->name,
                    'is_active' => (bool) $vessel->is_active,
                    'imo_no' => $vessel->imo_no,
                    'call_sign' => $vessel->call_sign,
                    'official_no' => $vessel->official_no,
                    'created_at' => $vessel->created_at?->toIso8601String(),
                ])
                ->values()
                ->all();

            $vesselsData = [
                'total_count' => $totalVesselsCount,
                'active_count' => $activeVesselsCount,
                'preview' => $vesselPreview,
            ];
        }

        $recentActivity = ($canViewAudit && $companyId > 0)
            ? RecentActivityQuery::for($user, $companyId, Client::class, (int) $client->id, 5)
            : [];

        return [
            'client' => [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
                'is_active' => (bool) $client->is_active,
                'created_at' => $client->created_at?->toIso8601String(),
                'updated_at' => $client->updated_at?->toIso8601String(),
                'is_in_use' => (bool) ($usageFlags['is_in_use'] ?? false),
                'can_delete' => (bool) ($usageFlags['can_delete'] ?? false),
                // The usage summary spans multiple permission domains. Keep only the generic
                // in-use/delete decision here; domain-specific counts live in guarded operations.
                'usage_count' => null,
                'usage_label' => null,
            ],
            'operations' => [
                'projects' => $projectsData,
                'vessels' => $vesselsData,
            ],
            'can' => [
                'update' => (bool) ($user?->can('settings.master-data.clients.update')),
                'delete' => $canDeletePermission,
                'view_projects' => $canViewProjects,
                'create_project' => (bool) ($user?->can('settings.master-data.projects.create')),
                'view_vessels' => $canViewVessels,
                'create_vessel' => (bool) ($user?->can('crew_operations.vessels.create')),
                'view_audit' => $canViewAudit,
            ],
            'recent_activity' => $recentActivity,
            'can_view_audit' => $canViewAudit,
        ];
    }
}
