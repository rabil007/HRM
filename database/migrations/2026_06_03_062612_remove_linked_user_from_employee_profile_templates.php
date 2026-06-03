<?php

use App\Models\EmployeeProfileTemplate;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        EmployeeProfileTemplate::query()->eachById(function (EmployeeProfileTemplate $template): void {
            $stored = $template->configuration_json;

            if (! is_array($stored)) {
                return;
            }

            $template->update([
                'configuration_json' => EmployeeProfileTemplateResolver::normalizeForStorage($stored),
            ]);
        });
    }

    public function down(): void
    {
        // Linked user is no longer part of the template registry.
    }
};
