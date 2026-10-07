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
    ];

    public function run(): void
    {
        foreach (self::SLUGS as $slug) {
            SeedBuiltInEmailTemplate::handle($slug);
        }
    }
}
