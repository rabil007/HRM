<?php

namespace App\Support\EmployeeDocuments\DocumentExpiryNotification;

use App\Enums\DocumentExpiryNotificationDeliveryType;
use App\Enums\DocumentExpiryNotificationRecipientKind;
use App\Models\Company;
use App\Models\DocumentExpiryNotificationRule;
use App\Models\DocumentExpiryNotificationRuleRecipient;
use App\Models\EmailTemplate;
use App\Support\Email\CommaSeparatedEmailList;
use Illuminate\Support\Facades\DB;

/**
 * One-time migration of legacy document_expiry_alert TO/CC presets into
 * company-scoped routing rules.
 *
 * Behaviour:
 * - Creates one enabled "All document types" rule per company when the global
 *   email template still has TO addresses.
 * - Recipients are stored as manual/external emails (the legacy presets were
 *   free-text addresses, not OMS-HRM user IDs).
 * - Clears template to_preset/cc_preset after copy so disabling/deleting a
 *   routing rule cannot silently revive the old global recipient list.
 * - Companies that already have any routing rule are skipped.
 */
class MigrateLegacyDocumentExpiryAlertRecipients
{
    public function handle(): int
    {
        $slug = (string) config('documents.expiry_alert_template_slug', 'document_expiry_alert');

        $template = EmailTemplate::query()->where('slug', $slug)->first();

        if ($template === null) {
            return 0;
        }

        $toEmails = CommaSeparatedEmailList::parse($template->to_preset);
        $ccEmails = CommaSeparatedEmailList::parse($template->cc_preset);

        if ($toEmails === []) {
            return 0;
        }

        $ccEmails = array_values(array_diff(
            array_map(strtolower(...), $ccEmails),
            array_map(strtolower(...), $toEmails),
        ));

        $created = 0;

        $companies = Company::query()->orderBy('id')->get(['id']);

        foreach ($companies as $company) {
            $alreadyHasRules = DocumentExpiryNotificationRule::query()
                ->where('company_id', $company->id)
                ->exists();

            if ($alreadyHasRules) {
                continue;
            }

            DB::transaction(function () use ($company, $toEmails, $ccEmails, &$created): void {
                $rule = DocumentExpiryNotificationRule::query()->create([
                    'company_id' => $company->id,
                    'name' => 'Employee Documents (migrated)',
                    'enabled' => true,
                    'all_document_types' => true,
                    'created_by' => null,
                    'updated_by' => null,
                ]);

                $now = now();
                $inserts = [];

                foreach ($toEmails as $email) {
                    $inserts[] = [
                        'rule_id' => $rule->id,
                        'company_id' => $company->id,
                        'recipient_kind' => DocumentExpiryNotificationRecipientKind::Email->value,
                        'user_id' => null,
                        'email' => strtolower(trim($email)),
                        'delivery_type' => DocumentExpiryNotificationDeliveryType::To->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach ($ccEmails as $email) {
                    $inserts[] = [
                        'rule_id' => $rule->id,
                        'company_id' => $company->id,
                        'recipient_kind' => DocumentExpiryNotificationRecipientKind::Email->value,
                        'user_id' => null,
                        'email' => strtolower(trim($email)),
                        'delivery_type' => DocumentExpiryNotificationDeliveryType::Cc->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($inserts !== []) {
                    DocumentExpiryNotificationRuleRecipient::query()->insert($inserts);
                }

                $created++;
            });
        }

        // Retire legacy presets so deleted/disabled rules never revive them.
        $template->forceFill([
            'to_preset' => null,
            'cc_preset' => null,
        ])->save();

        return $created;
    }
}
