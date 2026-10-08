<?php

namespace Database\Seeders;

use App\Support\Email\SeedBuiltInEmailTemplate;
use Illuminate\Database\Seeder;

class RequirementEmailTemplatesSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    public const SLUGS = [
        'requirement_submitted_for_approval',
        'requirement_assigned_for_approval',
        'requirement_approved',
        'requirement_returned',
        'requirement_target_date_three_days_before',
        'requirement_target_date_due_today',
        'requirement_deadline_extension_requested',
        'requirement_deadline_extension_approved',
        'requirement_deadline_extension_rejected',
        'requirement_deadline_extended_by_requester',
        'requirement_headcount_revision_requested',
        'requirement_headcount_revision_approved',
        'requirement_headcount_revision_rejected',
    ];

    public function run(): void
    {
        foreach (self::SLUGS as $slug) {
            SeedBuiltInEmailTemplate::handle($slug);
        }
    }
}
