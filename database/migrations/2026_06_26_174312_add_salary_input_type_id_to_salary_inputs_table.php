<?php

use App\Models\Company;
use App\Support\Payroll\ProvisionDefaultSalaryInputTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_inputs', function (Blueprint $table) {
            $table->foreignId('salary_input_type_id')
                ->nullable()
                ->after('period_id')
                ->constrained('salary_input_types')
                ->cascadeOnDelete();
        });

        $provisioner = app(ProvisionDefaultSalaryInputTypes::class);

        Company::query()->pluck('id')->each(function (int $companyId) use ($provisioner): void {
            $provisioner->handle($companyId);
        });

        DB::table('salary_inputs')
            ->orderBy('id')
            ->chunkById(100, function ($inputs) use ($provisioner): void {
                foreach ($inputs as $input) {
                    $type = $provisioner->findByLegacyCode((int) $input->company_id, (string) $input->type);

                    if ($type !== null) {
                        DB::table('salary_inputs')
                            ->where('id', $input->id)
                            ->update(['salary_input_type_id' => $type->id]);
                    }
                }
            });

        Schema::table('salary_inputs', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('salary_inputs', function (Blueprint $table) {
            $table->unsignedBigInteger('salary_input_type_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('salary_inputs', function (Blueprint $table) {
            $table->string('type', 32)->nullable()->after('period_id');
        });

        DB::table('salary_inputs')
            ->join('salary_input_types', 'salary_inputs.salary_input_type_id', '=', 'salary_input_types.id')
            ->select(['salary_inputs.id', 'salary_input_types.code'])
            ->orderBy('salary_inputs.id')
            ->chunkById(100, function ($inputs): void {
                foreach ($inputs as $input) {
                    DB::table('salary_inputs')
                        ->where('id', $input->id)
                        ->update(['type' => $input->code]);
                }
            }, 'salary_inputs.id', 'id');

        Schema::table('salary_inputs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salary_input_type_id');
        });
    }
};
