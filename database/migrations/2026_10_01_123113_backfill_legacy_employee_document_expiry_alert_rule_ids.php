<?php

use App\Models\DocumentExpiryNotificationRule;
use App\Models\EmployeeDocumentExpiryAlert;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Attach pre-routing ledger rows (notification_rule_id NULL) to each company's
 * migrated "Employee Documents (migrated)" rule so the same document+expiry is
 * not immediately re-eligible after routing goes live.
 *
 * Rows keep delivery_key = legacy so every recipient cohort for that migrated
 * rule is suppressed until the document expiry date changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $migratedRules = DocumentExpiryNotificationRule::query()
            ->where('name', 'Employee Documents (migrated)')
            ->where('all_document_types', true)
            ->get(['id', 'company_id']);

        foreach ($migratedRules as $rule) {
            EmployeeDocumentExpiryAlert::query()
                ->where('company_id', $rule->company_id)
                ->whereNull('notification_rule_id')
                ->update([
                    'notification_rule_id' => $rule->id,
                    'delivery_key' => 'legacy',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $migratedRuleIds = DocumentExpiryNotificationRule::query()
            ->where('name', 'Employee Documents (migrated)')
            ->where('all_document_types', true)
            ->pluck('id');

        if ($migratedRuleIds->isEmpty()) {
            return;
        }

        DB::table('employee_document_expiry_alerts')
            ->whereIn('notification_rule_id', $migratedRuleIds)
            ->where('delivery_key', 'legacy')
            ->update([
                'notification_rule_id' => null,
                'updated_at' => now(),
            ]);
    }
};
