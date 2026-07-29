<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return list<array{name: string, columns: list<string>}>
 */
function leaveRequestApprovalIndexes(): array
{
    return collect(Schema::getIndexes('leave_request_approvals'))
        ->map(fn (array $index): array => [
            'name' => (string) ($index['name'] ?? ''),
            'columns' => array_map('strtolower', $index['columns'] ?? []),
        ])
        ->values()
        ->all();
}

test('duplicate leave request approval approver status index is dropped safely', function () {
    $migration = '2026_07_29_143243_drop_duplicate_leave_request_approval_approver_status_index';
    $path = 'database/migrations/'.$migration.'.php';

    $hasOriginal = collect(leaveRequestApprovalIndexes())->contains(
        fn (array $index): bool => $index['name'] === 'leave_req_approvals_company_user_status_index'
            || $index['columns'] === ['company_id', 'approver_user_id', 'status'],
    );

    expect($hasOriginal)->toBeTrue();

    if (! collect(leaveRequestApprovalIndexes())->contains(
        fn (array $index): bool => $index['name'] === 'idx_lra_company_approver_status',
    )) {
        Schema::table('leave_request_approvals', function (Blueprint $table): void {
            $table->index(
                ['company_id', 'approver_user_id', 'status'],
                'idx_lra_company_approver_status',
            );
        });
    }

    expect(collect(leaveRequestApprovalIndexes())->where(
        fn (array $index): bool => $index['columns'] === ['company_id', 'approver_user_id', 'status'],
    ))->toHaveCount(2);

    DB::table('migrations')->where('migration', $migration)->delete();

    Artisan::call('migrate', [
        '--force' => true,
        '--path' => $path,
    ]);

    $after = leaveRequestApprovalIndexes();
    $companyApproverStatus = collect($after)->where(
        fn (array $index): bool => $index['columns'] === ['company_id', 'approver_user_id', 'status'],
    )->values();

    expect($companyApproverStatus)->toHaveCount(1)
        ->and(collect($after)->first(
            fn (array $index): bool => $index['name'] === 'idx_lra_company_approver_status',
        ))->toBeNull()
        ->and(collect($after)->contains(
            fn (array $index): bool => $index['name'] === 'leave_req_approvals_company_user_status_index'
                || $index['columns'] === ['company_id', 'approver_user_id', 'status'],
        ))->toBeTrue()
        ->and(collect($after)->contains(
            fn (array $index): bool => $index['columns'] === ['company_id', 'policy_id'],
        ))->toBeTrue();

    Artisan::call('migrate', [
        '--force' => true,
        '--path' => $path,
    ]);

    expect(collect(leaveRequestApprovalIndexes())->where(
        fn (array $index): bool => $index['columns'] === ['company_id', 'approver_user_id', 'status'],
    ))->toHaveCount(1);

    Artisan::call('migrate:rollback', [
        '--force' => true,
        '--path' => $path,
    ]);

    $afterRollback = leaveRequestApprovalIndexes();

    expect(collect($afterRollback)->where(
        fn (array $index): bool => $index['columns'] === ['company_id', 'approver_user_id', 'status'],
    ))->toHaveCount(1)
        ->and(collect($afterRollback)->first(
            fn (array $index): bool => $index['name'] === 'idx_lra_company_approver_status',
        ))->toBeNull()
        ->and(collect($afterRollback)->contains(
            fn (array $index): bool => $index['name'] === 'leave_req_approvals_company_user_status_index'
                || $index['columns'] === ['company_id', 'approver_user_id', 'status'],
        ))->toBeTrue();
});
