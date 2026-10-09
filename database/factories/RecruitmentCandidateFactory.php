<?php

namespace Database\Factories;

use App\Enums\Recruitment\CandidateStage;
use App\Models\RecruitmentCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentCandidate>
 */
class RecruitmentCandidateFactory extends Factory
{
    protected $model = RecruitmentCandidate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'company_id' => 1,
            'recruitment_requirement_id' => null,
            'recruitment_requirement_line_id' => null,
            'requirement_number_snapshot' => 'REQ-'.fake()->numerify('####'),
            'position_title_snapshot' => fake()->jobTitle(),
            'name' => fake()->name(),
            'email' => $email,
            'phone' => null,
            'email_normalized' => strtolower(trim($email)),
            'phone_normalized' => null,
            'nationality_id' => null,
            'source' => null,
            'notes' => null,
            'stage' => CandidateStage::Applied,
            'interview_outcome' => null,
            'rejection_reason' => null,
            'pre_rejection_stage' => null,
            'interview_scheduled_at' => null,
            'interviewer_user_id' => null,
            'external_interviewer_name' => null,
            'interview_mode' => null,
            'interview_location' => null,
            'interview_feedback' => null,
            'cv_path' => null,
            'cv_original_file_name' => null,
            'cv_mime_type' => null,
            'cv_file_size_bytes' => null,
            'cv_file_checksum' => null,
            'lock_version' => 0,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
