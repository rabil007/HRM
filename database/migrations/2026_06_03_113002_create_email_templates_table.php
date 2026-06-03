<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('category');
            $table->string('subject');
            $table->text('body_html');
            $table->boolean('is_default')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'enabled']);
        });

        DB::table('email_templates')->insert([
            'slug' => 'document_share',
            'label' => 'Document share',
            'category' => 'document',
            'subject' => 'Documents from Overseas Marine Services',
            'body_html' => "Hello,\n\nPlease find the attached employee documents.\n\nThank you.",
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
