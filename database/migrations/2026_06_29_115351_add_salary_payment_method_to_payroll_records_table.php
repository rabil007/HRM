<?php

use App\Models\PayrollRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->string('salary_payment_method', 30)->nullable()->after('payroll_category');
        });

        PayrollRecord::withoutGlobalScopes()
            ->with('employee:id,salary_payment_method')
            ->chunkById(200, function ($records): void {
                foreach ($records as $record) {
                    $method = $record->employee?->salary_payment_method?->value ?? 'bank_transfer';

                    $record->forceFill(['salary_payment_method' => $method])->saveQuietly();
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropColumn('salary_payment_method');
        });
    }
};
