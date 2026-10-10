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
     *     offer_prepare: bool,
     *     offer_update: bool,
     *     offer_send: bool,
     *     offer_decide: bool,
     *     offer_revise: bool,
     *     offer_download: bool,
     *     joining_confirm: bool,
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
            'offer_prepare' => (bool) $user?->can('recruitment.candidates.offer.prepare'),
            'offer_update' => (bool) $user?->can('recruitment.candidates.offer.update'),
            'offer_send' => (bool) $user?->can('recruitment.candidates.offer.send'),
            'offer_decide' => (bool) $user?->can('recruitment.candidates.offer.decide'),
            'offer_revise' => (bool) $user?->can('recruitment.candidates.offer.revise'),
            'offer_download' => (bool) $user?->can('recruitment.candidates.offer.download'),
            'joining_confirm' => (bool) $user?->can('recruitment.candidates.joining.confirm'),
            'view_audit' => (bool) $user?->can('audit.view'),
        ];
    }
}
