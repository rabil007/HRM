<?php

use App\Support\Migrations\EnsureCompanyDocumentExpiryNotificationRecipientsTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create or repair the recipients table without dropping rows.
     *
     * Fresh databases create the table. If a previous MySQL DDL attempt left a
     * partial table, missing indexes/foreign keys are added in place. The table
     * is dropped and recreated only when it exists, is empty, and is missing
     * required columns. Existing recipient rows are never discarded.
     */
    public function up(): void
    {
        EnsureCompanyDocumentExpiryNotificationRecipientsTable::up();
    }

    public function down(): void
    {
        Schema::dropIfExists(EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE);
    }
};
