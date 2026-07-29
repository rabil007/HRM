<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_request_approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('policy_id')->nullable()->after('policy_step_id');
            $table->string('policy_name')->nullable()->after('policy_id');
            $table->string('policy_step_label')->nullable()->after('policy_name');

            $table->foreign('policy_id', 'leave_req_approvals_policy_fk')
                ->references('id')
                ->on('leave_approval_policies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_request_approvals', function (Blueprint $table) {
            $table->dropForeign('leave_req_approvals_policy_fk');
            $table->dropColumn(['policy_id', 'policy_name', 'policy_step_label']);
        });
    }
};
