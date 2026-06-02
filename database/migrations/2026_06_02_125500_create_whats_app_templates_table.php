<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('category');
            $table->string('meta_name');
            $table->string('meta_language', 16);
            $table->string('header_type')->default('document');
            $table->text('body_preview');
            $table->boolean('is_default')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category', 'enabled']);
        });

        $seed = config('whatsapp.default_document_template');

        DB::table('whatsapp_templates')->insert([
            'slug' => $seed['slug'],
            'label' => $seed['label'],
            'category' => 'document',
            'meta_name' => $seed['meta_name'],
            'meta_language' => $seed['meta_language'],
            'header_type' => 'document',
            'body_preview' => $seed['body_preview'],
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
