<?php

use App\Support\BulkDocuments\SalaryDeclarationGenerationProgress;
use Illuminate\Support\Facades\Cache;

test('salary declaration generation progress tracks running and completed states', function () {
    Cache::flush();

    expect(SalaryDeclarationGenerationProgress::forCompany(1))
        ->toMatchArray([
            'status' => 'idle',
            'generated' => 0,
            'skipped' => 0,
        ]);

    SalaryDeclarationGenerationProgress::markQueued(1);

    expect(SalaryDeclarationGenerationProgress::forCompany(1))
        ->status->toBe('running')
        ->message->toBe('Salary declaration generation queued...');

    SalaryDeclarationGenerationProgress::update(1, [
        'status' => 'running',
        'message' => 'Generated 5 salary declaration(s) for Acme so far. Processing continues...',
        'generated' => 5,
        'skipped' => 2,
    ]);

    expect(SalaryDeclarationGenerationProgress::forCompany(1))
        ->generated->toBe(5)
        ->skipped->toBe(2);

    SalaryDeclarationGenerationProgress::update(1, [
        'status' => 'completed',
        'message' => 'Generated 10 salary declaration(s) for Acme. Skipped 2 employee(s) with existing documents.',
        'generated' => 10,
        'skipped' => 2,
        'finished_at' => now()->toIso8601String(),
    ]);

    expect(SalaryDeclarationGenerationProgress::forCompany(1))
        ->status->toBe('completed')
        ->generated->toBe(10);
});
