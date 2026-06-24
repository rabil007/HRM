<?php

namespace Database\Seeders;

use App\Enums\EmailTemplateCategory;
use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        self::seedPayslipDeliveryTemplate();
        self::seedLeaveRequestSubmittedTemplate();
    }

    public static function seedPayslipDeliveryTemplate(): EmailTemplate
    {
        $template = EmailTemplate::query()->updateOrCreate(
            ['slug' => 'payslip_delivery'],
            [
                'label' => 'Payslip delivery',
                'category' => EmailTemplateCategory::Payroll,
                'to_preset' => null,
                'cc_preset' => null,
                'dispatch_at' => null,
                'subject' => 'Your payslip for {{period_name}} — {{company_name}}',
                'body_html' => self::payslipDeliveryBody(),
                'enabled' => true,
                'sort_order' => 0,
            ],
        );

        if (! $template->is_default) {
            $template->markAsDefaultForCategory();
        }

        return $template->fresh();
    }

    public static function seedLeaveRequestSubmittedTemplate(): EmailTemplate
    {
        $template = EmailTemplate::query()->updateOrCreate(
            ['slug' => 'leave_request_submitted'],
            [
                'label' => 'Leave request submitted',
                'category' => EmailTemplateCategory::Hr,
                'to_preset' => null,
                'cc_preset' => null,
                'dispatch_at' => null,
                'subject' => 'New leave request — {{employee_name}} ({{leave_type}})',
                'body_html' => self::leaveRequestSubmittedBody(),
                'enabled' => true,
                'sort_order' => 0,
            ],
        );

        if (! $template->is_default) {
            $template->markAsDefaultForCategory();
        }

        return $template->fresh();
    }

    private static function payslipDeliveryBody(): string
    {
        return <<<'TEXT'
Dear {{employee_name}},

Please find your payslip for {{period_name}} attached to this email.

Employee no.: {{employee_no}}
Net salary: {{net_salary}}

If you have any questions about your payslip, please contact HR.

Thank you,
{{company_name}}
TEXT;
    }

    private static function leaveRequestSubmittedBody(): string
    {
        return <<<'TEXT'
A new leave request has been submitted and is pending your review.

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
TEXT;
    }
}
