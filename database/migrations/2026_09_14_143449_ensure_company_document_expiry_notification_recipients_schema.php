<?php

use App\Support\Migrations\EnsureCompanyDocumentExpiryNotificationRecipientsTable;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Additive repair for environments that already ran the original recipients
     * migration. Idempotent: creates the table if missing, adds indexes when
     * practical, and never drops a table that contains recipient rows.
     */
    public function up(): void
    {
        EnsureCompanyDocumentExpiryNotificationRecipientsTable::up();
    }

    public function down(): void
    {
        // Non-destructive: do not drop recipient configuration on rollback.
    }
};
