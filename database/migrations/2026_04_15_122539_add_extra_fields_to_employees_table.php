<?php

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
        Schema::table('employees', function (Blueprint $table) {
            $table->string('nearest_airport', 150)->nullable()->after('phone');
            $table->string('phone_home_country', 30)->nullable()->after('nearest_airport');
            $table->string('cv_source', 120)->nullable()->after('phone_home_country');
            $table->string('emergency_contact_home_country', 200)->nullable()->after('emergency_phone');
            $table->string('emergency_phone_home_country', 30)->nullable()->after('emergency_contact_home_country');
            $table->string('place_of_birth', 150)->nullable()->after('date_of_birth');
            $table->string('religion', 120)->nullable()->after('gender');
            $table->string('labor_contract_id', 100)->nullable()->after('contract_end_date');
            $table->date('passport_issued_at')->nullable()->after('passport_number');
            $table->string('spouse_name', 200)->nullable()->after('marital_status');
            $table->date('spouse_birthdate')->nullable()->after('spouse_name');
            $table->unsignedSmallInteger('dependent_children_count')->nullable()->after('spouse_birthdate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'nearest_airport',
                'phone_home_country',
                'cv_source',
                'emergency_contact_home_country',
                'emergency_phone_home_country',
                'place_of_birth',
                'religion',
                'labor_contract_id',
                'passport_issued_at',
                'spouse_name',
                'spouse_birthdate',
                'dependent_children_count',
            ]);
        });
    }
};
