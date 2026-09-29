<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function clientProjectPivotMigration()
{
    return require database_path('migrations/2026_09_29_000001_create_client_project_table.php');
}

function dropProjectIdMigration()
{
    return require database_path('migrations/2026_09_29_000002_drop_client_id_from_projects_table.php');
}

test('migration backfills legacy projects client_id to client_project pivot table', function () {
    $dropMigration = dropProjectIdMigration();
    $pivotMigration = clientProjectPivotMigration();

    // 0. Temporarily restore client_id column to test legacy 000001 migration
    if (! Schema::hasColumn('projects', 'client_id')) {
        $dropMigration->down();
    }

    // 1. Simulate pre-migration state by dropping the pivot table
    $pivotMigration->down();
    expect(Schema::hasTable('client_project'))->toBeFalse();

    // 2. Insert clients directly into DB
    $client5Id = DB::table('clients')->insertGetId([
        'name' => 'Backfill Client 5',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $client6Id = DB::table('clients')->insertGetId([
        'name' => 'Backfill Client 6',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. Insert projects directly via DB (bypassing Eloquent saved hook)
    $now = now();
    DB::table('projects')->insert([
        [
            'id' => 10,
            'client_id' => $client5Id,
            'title' => 'Backfill Project 10',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => 20,
            'client_id' => null,
            'title' => 'Backfill Project 20 Null Client',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => 30,
            'client_id' => $client6Id,
            'title' => 'Backfill Project 30',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    // 4. Run the migration up()
    $pivotMigration->up();

    // 5. Assert pivot table exists and backfill completed correctly
    expect(Schema::hasTable('client_project'))->toBeTrue();

    // - Original Project row unchanged
    $project10 = DB::table('projects')->where('id', 10)->first();
    expect($project10)->not->toBeNull()
        ->and((int) $project10->client_id)->toBe($client5Id)
        ->and($project10->title)->toBe('Backfill Project 10');

    // - Original Client row unchanged
    $client5 = DB::table('clients')->where('id', $client5Id)->first();
    expect($client5)->not->toBeNull()
        ->and($client5->name)->toBe('Backfill Client 5');

    // - Pivot row exists once for Project 10 -> Client 5
    $pivotRows10 = DB::table('client_project')->where('project_id', 10)->get();
    expect($pivotRows10)->toHaveCount(1)
        ->and((int) $pivotRows10->first()->client_id)->toBe($client5Id);

    // - Pivot row exists once for Project 30 -> Client 6
    $pivotRows30 = DB::table('client_project')->where('project_id', 30)->get();
    expect($pivotRows30)->toHaveCount(1)
        ->and((int) $pivotRows30->first()->client_id)->toBe($client6Id);

    // - Null legacy client_id does NOT create an invalid pivot record
    $pivotRows20 = DB::table('client_project')->where('project_id', 20)->get();
    expect($pivotRows20)->toHaveCount(0);

    // - Total pivot records matches non-null projects
    expect(DB::table('client_project')->whereIn('project_id', [10, 20, 30])->count())->toBe(2);

    // 6. Prove duplicate backfill does not occur if backfill runs again
    DB::table('projects')
        ->whereIn('id', [10, 20, 30])
        ->whereNotNull('client_id')
        ->orderBy('id')
        ->chunkById(500, function ($projects): void {
            $now = now();
            $rows = [];
            foreach ($projects as $project) {
                $rows[] = [
                    'client_id' => (int) $project->client_id,
                    'project_id' => (int) $project->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                DB::table('client_project')->insertOrIgnore($rows);
            }
        });

    expect(DB::table('client_project')->whereIn('project_id', [10, 20, 30])->count())->toBe(2)
        ->and(DB::table('client_project')->where('project_id', 10)->count())->toBe(1)
        ->and(DB::table('client_project')->where('project_id', 30)->count())->toBe(1);

    // Re-apply drop migration so schema stays clean for following tests
    $dropMigration->up();
});

test('drop client_id migration drops column on up and restores deterministically on down', function () {
    $dropMigration = dropProjectIdMigration();

    // Ensure initial state has column dropped
    if (Schema::hasColumn('projects', 'client_id')) {
        $dropMigration->up();
    }
    expect(Schema::hasColumn('projects', 'client_id'))->toBeFalse();

    // Create clients and projects with pivot records
    $clientAId = DB::table('clients')->insertGetId([
        'name' => 'Drop Test Client A',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $clientBId = DB::table('clients')->insertGetId([
        'name' => 'Drop Test Client B',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $projectId = DB::table('projects')->insertGetId([
        'title' => 'Drop Test Project',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Attach both clients to the project
    DB::table('client_project')->insert([
        ['client_id' => $clientBId, 'project_id' => $projectId, 'created_at' => now(), 'updated_at' => now()],
        ['client_id' => $clientAId, 'project_id' => $projectId, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Run down() to roll back the drop
    $dropMigration->down();

    expect(Schema::hasColumn('projects', 'client_id'))->toBeTrue();

    // Assert it backfills with the lowest client_id (min(clientAId, clientBId))
    $restoredProject = DB::table('projects')->where('id', $projectId)->first();
    $expectedLowestClientId = min($clientAId, $clientBId);
    expect((int) $restoredProject->client_id)->toBe($expectedLowestClientId);

    // Run up() to re-drop
    $dropMigration->up();

    expect(Schema::hasColumn('projects', 'client_id'))->toBeFalse();
});
