<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_signing_preset_steps', 'step_label')) {
            Schema::table('document_signing_preset_steps', function (Blueprint $table) {
                $table->string('step_label', 120)
                    ->nullable()
                    ->after('target_user_id');
            });
        }

        if (! Schema::hasColumn('document_recipient_requests', 'signature_slot_key')) {
            Schema::table('document_recipient_requests', function (Blueprint $table) {
                $table->string('signature_slot_key', 64)
                    ->nullable()
                    ->after('signing_step_sequence');
            });
        }

        if (! Schema::hasColumn('document_recipient_requests', 'signing_step_label_snapshot')) {
            Schema::table('document_recipient_requests', function (Blueprint $table) {
                $table->string('signing_step_label_snapshot', 120)
                    ->nullable()
                    ->after('signature_slot_key');
            });
        }

        $this->assertNoDuplicateSigningFlowSteps();

        // Add the unique replacement first so MySQL keeps a left-prefix index on
        // document_signing_flow_id for the existing foreign key.
        if (! Schema::hasIndex('document_recipient_requests', 'doc_rr_sign_flow_step_uq')) {
            Schema::table('document_recipient_requests', function (Blueprint $table) {
                $table->unique(
                    ['document_signing_flow_id', 'signing_step_sequence'],
                    'doc_rr_sign_flow_step_uq',
                );
            });
        }

        if (Schema::hasIndex('document_recipient_requests', 'doc_rr_sign_flow_step_idx')) {
            Schema::table('document_recipient_requests', function (Blueprint $table) {
                $table->dropIndex('doc_rr_sign_flow_step_idx');
            });
        }
    }

    public function down(): void
    {
        // Restore the non-unique FK-supporting index before dropping the unique.
        if (! Schema::hasIndex('document_recipient_requests', 'doc_rr_sign_flow_step_idx')) {
            Schema::table('document_recipient_requests', function (Blueprint $table) {
                $table->index(
                    ['document_signing_flow_id', 'signing_step_sequence'],
                    'doc_rr_sign_flow_step_idx',
                );
            });
        }

        if (Schema::hasIndex('document_recipient_requests', 'doc_rr_sign_flow_step_uq')) {
            Schema::table('document_recipient_requests', function (Blueprint $table) {
                $table->dropUnique('doc_rr_sign_flow_step_uq');
            });
        }

        $recipientColumns = array_values(array_filter([
            Schema::hasColumn('document_recipient_requests', 'signature_slot_key')
                ? 'signature_slot_key'
                : null,
            Schema::hasColumn('document_recipient_requests', 'signing_step_label_snapshot')
                ? 'signing_step_label_snapshot'
                : null,
        ]));

        if ($recipientColumns !== []) {
            Schema::table('document_recipient_requests', function (Blueprint $table) use ($recipientColumns) {
                $table->dropColumn($recipientColumns);
            });
        }

        if (Schema::hasColumn('document_signing_preset_steps', 'step_label')) {
            Schema::table('document_signing_preset_steps', function (Blueprint $table) {
                $table->dropColumn('step_label');
            });
        }
    }

    private function assertNoDuplicateSigningFlowSteps(): void
    {
        $duplicates = DB::table('document_recipient_requests')
            ->select([
                'document_signing_flow_id',
                'signing_step_sequence',
                DB::raw('COUNT(*) as aggregate'),
            ])
            ->whereNotNull('document_signing_flow_id')
            ->whereNotNull('signing_step_sequence')
            ->groupBy('document_signing_flow_id', 'signing_step_sequence')
            ->having('aggregate', '>', 1)
            ->limit(10)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $sample = $duplicates
            ->map(fn ($row): string => sprintf(
                'flow=%s step=%s count=%s',
                (string) $row->document_signing_flow_id,
                (string) $row->signing_step_sequence,
                (string) $row->aggregate,
            ))
            ->implode('; ');

        throw new RuntimeException(
            'Cannot create unique index doc_rr_sign_flow_step_uq: duplicate document_signing_flow_id + signing_step_sequence rows exist. Resolve duplicates before migrating. Samples: '.$sample
        );
    }
};
