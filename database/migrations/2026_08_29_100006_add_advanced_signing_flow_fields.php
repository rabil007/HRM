<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_signing_preset_steps', function (Blueprint $table) {
            $table->string('step_label', 120)
                ->nullable()
                ->after('target_user_id');
        });

        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->string('signature_slot_key', 64)
                ->nullable()
                ->after('signing_step_sequence');
            $table->string('signing_step_label_snapshot', 120)
                ->nullable()
                ->after('signature_slot_key');

            $table->dropIndex('doc_rr_sign_flow_step_idx');
            $table->unique(
                ['document_signing_flow_id', 'signing_step_sequence'],
                'doc_rr_sign_flow_step_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->dropUnique('doc_rr_sign_flow_step_uq');
            $table->index(
                ['document_signing_flow_id', 'signing_step_sequence'],
                'doc_rr_sign_flow_step_idx',
            );
            $table->dropColumn([
                'signature_slot_key',
                'signing_step_label_snapshot',
            ]);
        });

        Schema::table('document_signing_preset_steps', function (Blueprint $table) {
            $table->dropColumn('step_label');
        });
    }
};
