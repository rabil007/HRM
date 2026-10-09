<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Models\User;
use Carbon\Carbon;

final class CandidateOfferPresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public static function summary(?RecruitmentCandidateOffer $offer): ?array
    {
        if ($offer === null) {
            return null;
        }

        return [
            'id' => (int) $offer->id,
            'status' => $offer->status->value,
            'status_label' => $offer->status->label(),
            'status_badge' => $offer->status->badgeVariant(),
            'revision_number' => (int) $offer->revision_number,
            'salary_amount' => (string) $offer->salary_amount,
            'salary_currency_code' => (string) $offer->salary_currency_code,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function detail(
        ?RecruitmentCandidateOffer $offer,
        RecruitmentCandidate $candidate,
        User $user,
        string $timezone,
    ): ?array {
        if ($offer === null) {
            return null;
        }

        $canOwn = CandidateWorkflowAuthorization::hasOwnershipOrManage($user, $candidate->requirement);
        $parentsValid = CandidateWorkflowAuthorization::hasValidParentsForActions($candidate);
        $canDownload = CandidateWorkflowAuthorization::canDownloadOfferDocuments($user, $candidate);

        return [
            'id' => (int) $offer->id,
            'revision_number' => (int) $offer->revision_number,
            'is_current' => (bool) $offer->is_current,
            'supersedes_offer_id' => $offer->supersedes_offer_id,
            'status' => $offer->status->value,
            'status_label' => $offer->status->label(),
            'status_badge' => $offer->status->badgeVariant(),
            'salary_amount' => (string) $offer->salary_amount,
            'salary_currency_code' => (string) $offer->salary_currency_code,
            'proposed_joining_date' => $offer->proposed_joining_date?->toDateString(),
            'offer_date' => $offer->offer_date?->toDateString(),
            'expiry_date' => $offer->expiry_date?->toDateString(),
            'notes' => $offer->notes,
            'sent_at' => self::formatDateTime($offer->sent_at, $timezone),
            'sent_by_name' => $offer->sender?->name,
            'accepted_at' => self::formatDateTime($offer->accepted_at, $timezone),
            'accepted_by_name' => $offer->acceptor?->name,
            'rejected_at' => self::formatDateTime($offer->rejected_at, $timezone),
            'rejected_by_name' => $offer->rejector?->name,
            'rejection_reason' => $offer->rejection_reason,
            'revision_reason' => $offer->revision_reason,
            'lock_version' => (int) $offer->lock_version,
            'has_offer_document' => $offer->hasOfferDocument(),
            'offer_document_original_file_name' => $offer->offer_document_original_file_name,
            'has_acceptance_document' => $offer->hasAcceptanceDocument(),
            'acceptance_document_original_file_name' => $offer->acceptance_document_original_file_name,
            'can_update' => $canOwn
                && $parentsValid
                && $user->can('recruitment.candidates.offer.update')
                && CandidateWorkflowAuthorization::canUpdateOffer($offer),
            'can_send' => $canOwn
                && $parentsValid
                && $user->can('recruitment.candidates.offer.send')
                && CandidateWorkflowAuthorization::canSendOffer($offer),
            'can_accept' => $canOwn
                && $parentsValid
                && $user->can('recruitment.candidates.offer.decide')
                && CandidateWorkflowAuthorization::canDecideOffer($offer, $candidate),
            'can_reject' => $canOwn
                && $parentsValid
                && $user->can('recruitment.candidates.offer.decide')
                && CandidateWorkflowAuthorization::canDecideOffer($offer, $candidate),
            'can_revise' => $canOwn
                && $parentsValid
                && $user->can('recruitment.candidates.offer.revise')
                && $user->can('recruitment.candidates.manage')
                && CandidateWorkflowAuthorization::canReviseOffer($offer, $candidate),
            'can_download_offer_document' => $canDownload && $offer->hasOfferDocument(),
            'can_download_acceptance_document' => $canDownload && $offer->hasAcceptanceDocument(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function history(RecruitmentCandidate $candidate, string $timezone): array
    {
        return $candidate->offers
            ->sortByDesc('revision_number')
            ->values()
            ->map(function (RecruitmentCandidateOffer $offer) use ($timezone): array {
                return [
                    'id' => (int) $offer->id,
                    'revision_number' => (int) $offer->revision_number,
                    'is_current' => (bool) $offer->is_current,
                    'status' => $offer->status->value,
                    'status_label' => $offer->status->label(),
                    'salary_amount' => (string) $offer->salary_amount,
                    'salary_currency_code' => (string) $offer->salary_currency_code,
                    'revision_reason' => $offer->revision_reason,
                    'created_at' => self::formatDateTime($offer->created_at, $timezone),
                ];
            })
            ->all();
    }

    private static function formatDateTime(mixed $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Carbon) {
            try {
                $value = Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return $value->copy()->timezone($timezone)->format('Y-m-d H:i');
    }
}
