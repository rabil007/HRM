<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DocumentTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = '/Users/mohammedrabil/Downloads/Document Types (x_document_types).csv';

        if (! file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! $lines) {
            return;
        }

        $rows = array_map(function (string $line) {
            $values = str_getcsv($line);

            return trim((string) ($values[0] ?? ''));
        }, $lines);

        $rows = array_values(array_filter($rows));

        if (count($rows) > 0 && mb_strtolower($rows[0]) === 'title') {
            array_shift($rows);
        }

        foreach ($rows as $title) {
            $slug = Str::slug($title);

            if (! $slug) {
                continue;
            }

            DocumentType::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'title' => $title,
                    'is_active' => true,
                ]
            );
        }
    }
}
