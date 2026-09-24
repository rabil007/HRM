<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_REMAINING = '(`entitled_days` + `carried_days` - `opening_used_days` - `used_days` - `pending_days`)';

    private const OLD_REMAINING = '(`entitled_days` + `carried_days` - `used_days` - `pending_days`)';

    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table): void {
            $table->decimal('opening_used_days', 6, 2)->default(0)->after('carried_days');
            $table->date('opening_balance_as_of')->nullable()->after('opening_used_days');
            $table->text('opening_balance_note')->nullable()->after('opening_balance_as_of');
        });

        Schema::table('leave_balances', function (Blueprint $table): void {
            $table->dropColumn('remaining_days');
        });

        Schema::table('leave_balances', function (Blueprint $table): void {
            $table->decimal('remaining_days', 6, 2)->storedAs(self::NEW_REMAINING);
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table): void {
            $table->dropColumn('remaining_days');
        });

        Schema::table('leave_balances', function (Blueprint $table): void {
            $table->dropColumn([
                'opening_used_days',
                'opening_balance_as_of',
                'opening_balance_note',
            ]);
        });

        Schema::table('leave_balances', function (Blueprint $table): void {
            $table->decimal('remaining_days', 6, 2)->storedAs(self::OLD_REMAINING);
        });
    }
};
