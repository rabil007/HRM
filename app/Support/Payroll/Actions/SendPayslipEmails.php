<?php

namespace App\Support\Payroll\Actions;

use App\Enums\EmailTemplateCategory;
use App\Mail\PayslipMail;
use App\Models\EmailTemplate;
use App\Models\PayrollRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SendPayslipEmails
{
    /**
     * @param  Collection<int, PayrollRecord>  $records
     * @return array{sent: int, skipped: int, errors: list<string>}
     */
    public function handle(Collection $records): array
    {
        $template = $this->resolveTemplate();
        $sent = 0;
        $skipped = 0;
        $errors = [];

        foreach ($records as $record) {
            $record->loadMissing(['employee', 'period', 'company']);

            if (! filled($record->payslip_path) || ! Storage::disk('local')->exists($record->payslip_path)) {
                $skipped++;
                $errors[] = "Payslip PDF missing for {$record->employee?->name}.";

                continue;
            }

            $recipient = $record->employee?->work_email ?: $record->employee?->personal_email;

            if (! filled($recipient)) {
                $skipped++;
                $errors[] = "No email address for {$record->employee?->name}.";

                continue;
            }

            $subject = $this->renderTemplate($template->subject, $record);
            $body = $this->renderTemplate($template->body_html, $record);
            $filename = 'payslip-'.Str::slug((string) ($record->employee?->employee_no ?: 'employee')).'.pdf';

            Mail::to($recipient)->queue(new PayslipMail(
                subjectLine: $subject,
                bodyHtml: $body,
                attachmentPath: (string) $record->payslip_path,
                attachmentName: $filename,
            ));

            $sent++;
        }

        return compact('sent', 'skipped', 'errors');
    }

    private function resolveTemplate(): EmailTemplate
    {
        try {
            return EmailTemplate::defaultForCategory(EmailTemplateCategory::Payroll);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'template' => 'No enabled payroll email template is configured.',
            ]);
        }
    }

    private function renderTemplate(string $template, PayrollRecord $record): string
    {
        $replacements = [
            '{{employee_name}}' => (string) ($record->employee?->name ?? ''),
            '{{employee_no}}' => (string) ($record->employee?->employee_no ?? ''),
            '{{period_name}}' => (string) ($record->period?->name ?? ''),
            '{{net_salary}}' => number_format((float) $record->net_salary, 2, '.', ''),
            '{{company_name}}' => (string) ($record->company?->name ?? ''),
        ];

        return strtr($template, $replacements);
    }
}
