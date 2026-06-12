<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('public_holidays');
    }

    public function down(): void
    {
        //
    }
};
