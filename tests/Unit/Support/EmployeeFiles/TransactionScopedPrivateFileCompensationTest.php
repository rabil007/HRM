<?php

use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use App\Support\EmployeeDocuments\TrainingCertificateDocumentTypeResolver;
use App\Support\EmployeeFiles\TransactionScopedPrivateFileCompensation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    fakeEmployeeFileDisks();
    TransactionScopedPrivateFileCompensation::flushAll();
    TransactionScopedPrivateFileCompensation::registerListeners();
});

/**
 * @return array{employee: Employee, type: DocumentType, companyId: int}
 */
function compensationDocumentFixtures(): array
{
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    return [
        'employee' => $employee,
        'type' => $type,
        'companyId' => (int) $company->id,
    ];
}

test('sibling inner rollback keeps files from a previously committed inner transaction when outer commits', function () {
    ['employee' => $employee, 'type' => $type, 'companyId' => $companyId] = compensationDocumentFixtures();
    $store = app(StoresEmployeeDocument::class);

    $documentA = null;

    DB::transaction(function () use ($store, $employee, $type, $companyId, &$documentA): void {
        DB::transaction(function () use ($store, $employee, $type, $companyId, &$documentA): void {
            $documentA = $store->create(
                $employee,
                $type,
                UploadedFile::fake()->create('inner-a.pdf', 100, 'application/pdf'),
                ['title' => 'Inner A'],
                $companyId,
                null,
            );
        });

        try {
            DB::transaction(function (): void {
                throw new RuntimeException('Sibling B failed');
            });
        } catch (RuntimeException) {
            // Expected sibling failure; outer continues.
        }
    });

    expect($documentA)->toBeInstanceOf(EmployeeDocument::class)
        ->and(EmployeeDocument::query()->whereKey($documentA->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($documentA->file_path))->toBeTrue();

    // Still inside RefreshDatabase's outer frame; committed files must be re-tagged to that level.
    foreach (TransactionScopedPrivateFileCompensation::pendingForConnection() as $item) {
        expect($item['level'])->toBe(DB::transactionLevel());
    }
});

test('outer rollback after successful inner transaction removes the new document file', function () {
    ['employee' => $employee, 'type' => $type, 'companyId' => $companyId] = compensationDocumentFixtures();
    $store = app(StoresEmployeeDocument::class);

    $createdPath = null;

    try {
        DB::transaction(function () use ($store, $employee, $type, $companyId, &$createdPath): void {
            DB::transaction(function () use ($store, $employee, $type, $companyId, &$createdPath): void {
                $document = $store->create(
                    $employee,
                    $type,
                    UploadedFile::fake()->create('outer-rollback-a.pdf', 100, 'application/pdf'),
                    ['title' => 'Outer Rollback A'],
                    $companyId,
                    null,
                );
                $createdPath = $document->file_path;
            });

            throw new RuntimeException('Outer failed after inner A');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect(is_string($createdPath))->toBeTrue()
        ->and(EmployeeDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists((string) $createdPath))->toBeFalse();
});

test('sibling inner create-then-rollback removes only the sibling file', function () {
    ['employee' => $employee, 'type' => $type, 'companyId' => $companyId] = compensationDocumentFixtures();
    $store = app(StoresEmployeeDocument::class);

    $documentA = null;
    $pathB = null;

    DB::transaction(function () use ($store, $employee, $type, $companyId, &$documentA, &$pathB): void {
        DB::transaction(function () use ($store, $employee, $type, $companyId, &$documentA): void {
            $documentA = $store->create(
                $employee,
                $type,
                UploadedFile::fake()->create('keep-a.pdf', 100, 'application/pdf'),
                ['title' => 'Keep A'],
                $companyId,
                null,
            );
        });

        try {
            DB::transaction(function () use ($store, $employee, $type, $companyId, &$pathB): void {
                $documentB = $store->create(
                    $employee,
                    $type,
                    UploadedFile::fake()->create('drop-b.pdf', 100, 'application/pdf'),
                    ['title' => 'Drop B'],
                    $companyId,
                    null,
                );
                $pathB = $documentB->file_path;

                throw new RuntimeException('Sibling B failed after create');
            });
        } catch (RuntimeException) {
            // Expected.
        }
    });

    expect($documentA)->toBeInstanceOf(EmployeeDocument::class)
        ->and(EmployeeDocument::query()->whereKey($documentA->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($documentA->file_path))->toBeTrue()
        ->and(EmployeeDocument::query()->count())->toBe(1)
        ->and(is_string($pathB))->toBeTrue()
        ->and(Storage::disk('local')->exists((string) $pathB))->toBeFalse();
});

test('multiple nested commits then outer rollback remove all nested files', function () {
    ['employee' => $employee, 'type' => $type, 'companyId' => $companyId] = compensationDocumentFixtures();
    $store = app(StoresEmployeeDocument::class);

    $paths = [];

    try {
        DB::transaction(function () use ($store, $employee, $type, $companyId, &$paths): void {
            DB::transaction(function () use ($store, $employee, $type, $companyId, &$paths): void {
                DB::transaction(function () use ($store, $employee, $type, $companyId, &$paths): void {
                    $document = $store->create(
                        $employee,
                        $type,
                        UploadedFile::fake()->create('deep-a.pdf', 80, 'application/pdf'),
                        ['title' => 'Deep A'],
                        $companyId,
                        null,
                    );
                    $paths[] = $document->file_path;
                });

                $document = $store->create(
                    $employee,
                    $type,
                    UploadedFile::fake()->create('mid-b.pdf', 80, 'application/pdf'),
                    ['title' => 'Mid B'],
                    $companyId,
                    null,
                );
                $paths[] = $document->file_path;
            });

            throw new RuntimeException('Outer failed after nested commits');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect($paths)->toHaveCount(2)
        ->and(EmployeeDocument::query()->count())->toBe(0);

    foreach ($paths as $path) {
        expect(Storage::disk('local')->exists($path))->toBeFalse();
    }
});

test('successful outer commit retains nested files and clears tracking', function () {
    ['employee' => $employee, 'type' => $type, 'companyId' => $companyId] = compensationDocumentFixtures();
    $store = app(StoresEmployeeDocument::class);

    $document = null;

    DB::transaction(function () use ($store, $employee, $type, $companyId, &$document): void {
        DB::transaction(function () use ($store, $employee, $type, $companyId, &$document): void {
            $document = $store->create(
                $employee,
                $type,
                UploadedFile::fake()->create('commit-keep.pdf', 90, 'application/pdf'),
                ['title' => 'Commit Keep'],
                $companyId,
                null,
            );
        });
    });

    expect($document)->toBeInstanceOf(EmployeeDocument::class)
        ->and(EmployeeDocument::query()->whereKey($document->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($document->file_path))->toBeTrue();

    $pending = TransactionScopedPrivateFileCompensation::pendingForConnection();
    expect($pending)->not->toBeEmpty();
    foreach ($pending as $item) {
        expect($item['level'])->toBe(DB::transactionLevel())
            ->and($item['path'])->toBe($document->file_path);
    }
});

test('root transaction commit clears tracking while retaining remembered files', function () {
    fakeEmployeeFileDisks();
    TransactionScopedPrivateFileCompensation::flushAll();
    TransactionScopedPrivateFileCompensation::registerListeners();

    // End RefreshDatabase's wrapping transaction so we can exercise a true root commit.
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    $path = 'employee-documents/1/1/type-1/root-commit.pdf';
    Storage::disk('local')->put($path, 'kept');

    DB::transaction(function () use ($path): void {
        TransactionScopedPrivateFileCompensation::remember($path, 1);
        expect(TransactionScopedPrivateFileCompensation::pendingForConnection())->not->toBeEmpty();
    });

    expect(TransactionScopedPrivateFileCompensation::pendingForConnection())->toBe([])
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    DB::beginTransaction();
});

test('historical and original certificate files survive sibling and outer failure compensation', function () {
    ['employee' => $employee, 'type' => $type, 'companyId' => $companyId] = compensationDocumentFixtures();
    $store = app(StoresEmployeeDocument::class);

    $originalPath = "employee-documents/{$companyId}/{$employee->id}/type-{$type->id}/preexisting-original.pdf";
    $historicalPath = "employee-documents/{$companyId}/{$employee->id}/type-{$type->id}/preexisting-historical.pdf";
    Storage::disk('local')->put($originalPath, 'original-bytes');
    Storage::disk('local')->put($historicalPath, 'historical-bytes');

    $failedPath = null;

    try {
        DB::transaction(function () use ($store, $employee, $type, $companyId, &$failedPath): void {
            DB::transaction(function () use ($store, $employee, $type, $companyId, &$failedPath): void {
                $document = $store->create(
                    $employee,
                    $type,
                    UploadedFile::fake()->create('ephemeral.pdf', 70, 'application/pdf'),
                    ['title' => 'Ephemeral'],
                    $companyId,
                    null,
                );
                $failedPath = $document->file_path;
            });

            throw new RuntimeException('Outer failed');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect(Storage::disk('local')->exists($originalPath))->toBeTrue()
        ->and(Storage::disk('local')->exists($historicalPath))->toBeTrue()
        ->and(is_string($failedPath))->toBeTrue()
        ->and(Storage::disk('local')->exists((string) $failedPath))->toBeFalse();
});
