<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypesSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'Passport Copy',
            'Visa',
            'Emirates ID',
            'Labour Card',
            'Contract',
            'Certificate',
        ];

        foreach ($defaults as $title) {
            DocumentType::query()->firstOrCreate(
                ['title' => $title],
                ['is_active' => true],
            );
        }
    }
}
