<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\User;

final class CandidatePagePermissions
{
    /**
     * @return array{
     *     view: bool,
     *     create: bool,
     *     update: bool,
     *     move: bool,
     *     manage: bool,
     *     download_cv: bool,
     *     view_audit: bool,
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'view' => (bool) $user?->can('recruitment.candidates.view'),
            'create' => (bool) $user?->can('recruitment.candidates.create'),
            'update' => (bool) $user?->can('recruitment.candidates.update'),
            'move' => (bool) $user?->can('recruitment.candidates.move'),
            'manage' => (bool) $user?->can('recruitment.candidates.manage'),
            'download_cv' => (bool) $user?->can('recruitment.candidates.cv.download'),
            'view_audit' => (bool) $user?->can('audit.view'),
        ];
    }
}
