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
            $table->dropIndex('idx_emp_visa_exp');
            $table->dropIndex('idx_emp_eid_exp');
            $table->dropIndex('idx_emp_passport');

            $table->dropForeign(['visa_type_id']);

            $table->dropColumn([
                'gender',
                'religion',
                'bank_name',
                'bank_account_name',
                'visa_number',
                'visa_expiry',
                'visa_type',
                'visa_type_id',
                'emirates_id_expiry',
                'passport_issued_at',
                'passport_expiry',
                'work_permit_number',
                'work_permit_expiry',
                'labor_card_expiry',
                'mohre_uid',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('employees', 'gender')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('date_of_birth');
            });
        }

        if (! Schema::hasColumn('employees', 'religion')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('religion', 120)->nullable()->after('gender_id');
            });
        }

        if (! Schema::hasColumn('employees', 'bank_name')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('bank_name', 200)->nullable()->after('other_allowances');
            });
        }

        if (! Schema::hasColumn('employees', 'bank_account_name')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('bank_account_name', 200)->nullable()->after('bank_name');
            });
        }

        if (! Schema::hasColumn('employees', 'visa_number')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('visa_number', 100)->nullable()->after('bank_id');
            });
        }

        if (! Schema::hasColumn('employees', 'visa_expiry')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->date('visa_expiry')->nullable()->after('visa_number');
                $table->index('visa_expiry', 'idx_emp_visa_exp');
            });
        }

        if (! Schema::hasColumn('employees', 'visa_type')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('visa_type', 100)->nullable()->after('visa_expiry');
            });
        }

        if (! Schema::hasColumn('employees', 'visa_type_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->foreignId('visa_type_id')->nullable()->after('visa_type')->constrained('visa_types')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('employees', 'emirates_id_expiry')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->date('emirates_id_expiry')->nullable()->after('emirates_id');
                $table->index('emirates_id_expiry', 'idx_emp_eid_exp');
            });
        }

        if (! Schema::hasColumn('employees', 'passport_issued_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->date('passport_issued_at')->nullable()->after('passport_number');
            });
        }

        if (! Schema::hasColumn('employees', 'passport_expiry')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->date('passport_expiry')->nullable()->after('passport_issued_at');
                $table->index('passport_expiry', 'idx_emp_passport');
            });
        }

        if (! Schema::hasColumn('employees', 'work_permit_number')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('work_permit_number', 100)->nullable()->after('passport_expiry');
            });
        }

        if (! Schema::hasColumn('employees', 'work_permit_expiry')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->date('work_permit_expiry')->nullable()->after('work_permit_number');
            });
        }

        if (! Schema::hasColumn('employees', 'labor_card_expiry')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->date('labor_card_expiry')->nullable()->after('labor_card_number');
            });
        }

        if (! Schema::hasColumn('employees', 'mohre_uid')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('mohre_uid', 100)->nullable()->after('labor_card_expiry');
            });
        }
    }
};
