<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_expiry_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->boolean('all_document_types')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'enabled']);
        });

        Schema::create('document_expiry_notification_rule_document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')
                ->constrained('document_expiry_notification_rules', 'id', 'fk_doc_expiry_rule_dt_rule')
                ->cascadeOnDelete();
            $table->foreignId('document_type_id')
                ->constrained('document_types', 'id', 'fk_doc_expiry_rule_dt_type')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['rule_id', 'document_type_id'], 'doc_expiry_rule_document_type_unique');
        });

        Schema::create('document_expiry_notification_rule_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')
                ->constrained('document_expiry_notification_rules', 'id', 'fk_doc_expiry_rule_rcpt_rule')
                ->cascadeOnDelete();
            $table->foreignId('company_id')
                ->constrained('companies', 'id', 'fk_doc_expiry_rule_rcpt_company')
                ->cascadeOnDelete();
            $table->string('recipient_kind', 16);
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users', 'id', 'fk_doc_expiry_rule_rcpt_user')
                ->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('delivery_type', 8);
            $table->timestamps();

            $table->index(['rule_id', 'delivery_type'], 'doc_expiry_rule_rcpt_delivery_idx');
            $table->index(['company_id', 'recipient_kind'], 'doc_expiry_rule_rcpt_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_expiry_notification_rule_recipients');
        Schema::dropIfExists('document_expiry_notification_rule_document_types');
        Schema::dropIfExists('document_expiry_notification_rules');
    }
};
