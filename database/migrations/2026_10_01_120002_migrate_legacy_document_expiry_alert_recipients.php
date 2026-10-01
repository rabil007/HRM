<?php

use App\Support\EmployeeDocuments\DocumentExpiryNotification\MigrateLegacyDocumentExpiryAlertRecipients;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(MigrateLegacyDocumentExpiryAlertRecipients::class)->handle();
    }

    public function down(): void
    {
        // Intentional no-op: migrated rules and cleared template presets are retained.
    }
};
