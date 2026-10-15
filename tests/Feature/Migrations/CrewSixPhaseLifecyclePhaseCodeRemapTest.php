<?php

use App\Support\Migrations\CrewSixPhaseLifecyclePhaseCodeRemap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function crewPhaseRemapScratchTable(): string
{
    $table = 'crew_phase_remap_scratch_'.bin2hex(random_bytes(4));

    Schema::create($table, function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('phase_code');
    });

    return $table;
}

test('remap preserves valid legacy codes without collision', function () {
    $table = crewPhaseRemapScratchTable();
    $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;

    DB::table($table)->insert([
        ['phase_code' => 'p0'],
        ['phase_code' => 'p2a'],
        ['phase_code' => 'p2b'],
        ['phase_code' => 'p4'],
        ['phase_code' => 'p5'],
        ['phase_code' => 'p6'],
    ]);

    expect($remapper->classifyColumn($table, 'phase_code'))->toBe('legacy');

    $remapper->remapColumn($table, 'phase_code');

    $codes = DB::table($table)->orderBy('id')->pluck('phase_code')->all();

    expect($codes)->toBe(['p0', 'p1a', 'p1b', 'p2', 'p3', 'p4']);
});

test('old onboard p4 maps to p2 and old home p6 maps to p4 without collision', function () {
    $table = crewPhaseRemapScratchTable();
    $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;

    DB::table($table)->insert([
        ['phase_code' => 'p4'],
        ['phase_code' => 'p6'],
        ['phase_code' => 'p5'],
    ]);

    $remapper->remapColumn($table, 'phase_code');

    expect(DB::table($table)->orderBy('id')->pluck('phase_code')->all())
        ->toBe(['p2', 'p4', 'p3']);
});

test('remap aborts on travel in instead of folding into p1a', function () {
    $table = crewPhaseRemapScratchTable();
    $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;

    DB::table($table)->insert([
        ['phase_code' => 'p1'],
    ]);

    expect(fn () => $remapper->remapColumn($table, 'phase_code'))
        ->toThrow(RuntimeException::class, 'Cannot complete crew phase lifecycle remap')
        ->and(DB::table($table)->where('phase_code', CrewSixPhaseLifecyclePhaseCodeRemap::TEMP_TRAVEL)->count())
        ->toBe(1)
        ->and(DB::table($table)->where('phase_code', 'p1a')->count())
        ->toBe(0);
});

test('remap aborts on legacy ready to join p3 instead of folding into p1a', function () {
    $table = crewPhaseRemapScratchTable();
    $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;

    DB::table($table)->insert([
        ['phase_code' => 'p2a'],
        ['phase_code' => 'p3'],
    ]);

    expect($remapper->classifyColumn($table, 'phase_code'))->toBe('legacy');

    expect(fn () => $remapper->remapColumn($table, 'phase_code'))
        ->toThrow(RuntimeException::class, 'Ready to Join')
        ->and(DB::table($table)->where('phase_code', CrewSixPhaseLifecyclePhaseCodeRemap::TEMP_READY)->count())
        ->toBe(1)
        ->and(DB::table($table)->where('phase_code', 'p1a')->count())
        ->toBe(1);
});

test('successful remap cannot accidentally rerun against modern p4 home', function () {
    $table = crewPhaseRemapScratchTable();
    $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;

    DB::table($table)->insert([
        ['phase_code' => 'p2a'],
        ['phase_code' => 'p4'],
        ['phase_code' => 'p6'],
    ]);

    $remapper->remapColumn($table, 'phase_code');

    expect(DB::table($table)->orderBy('id')->pluck('phase_code')->all())
        ->toBe(['p1a', 'p2', 'p4'])
        ->and($remapper->classifyColumn($table, 'phase_code'))->toBe('modern');

    // Modern home p4 must not be remapped to onboard p2 on retry.
    $remapper->remapColumn($table, 'phase_code');
    $remapper->markComplete();
    $remapper->remapAllConfiguredTables();

    expect(DB::table($table)->orderBy('id')->pluck('phase_code')->all())
        ->toBe(['p1a', 'p2', 'p4'])
        ->and($remapper->isComplete())->toBeTrue();
});

test('partial mixed legacy and modern data fails instead of double converting', function () {
    $table = crewPhaseRemapScratchTable();
    $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;

    DB::table($table)->insert([
        ['phase_code' => 'p2'],
        ['phase_code' => 'p5'],
    ]);

    expect($remapper->classifyColumn($table, 'phase_code'))->toBe('mixed');

    expect(fn () => $remapper->remapColumn($table, 'phase_code'))
        ->toThrow(RuntimeException::class, 'dataset shape is [mixed]')
        ->and(DB::table($table)->where('phase_code', 'p5')->count())->toBe(1)
        ->and(DB::table($table)->where('phase_code', 'p2')->count())->toBe(1);
});

test('ambiguous p3 or p4 alone fails instead of guessing meaning', function () {
    $table = crewPhaseRemapScratchTable();
    $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;

    DB::table($table)->insert([
        ['phase_code' => 'p4'],
    ]);

    expect($remapper->classifyColumn($table, 'phase_code'))->toBe('ambiguous');

    expect(fn () => $remapper->remapColumn($table, 'phase_code'))
        ->toThrow(RuntimeException::class, 'dataset shape is [ambiguous]');
});

test('staging recoverable finalizes demob and home temps without reapplying p4 to p2', function () {
    $table = crewPhaseRemapScratchTable();
    $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;

    DB::table($table)->insert([
        ['phase_code' => 'p2'],
        ['phase_code' => CrewSixPhaseLifecyclePhaseCodeRemap::TEMP_DEMOB],
        ['phase_code' => CrewSixPhaseLifecyclePhaseCodeRemap::TEMP_HOME],
    ]);

    expect($remapper->classifyColumn($table, 'phase_code'))->toBe('staging_recoverable');

    $remapper->remapColumn($table, 'phase_code');

    expect(DB::table($table)->orderBy('id')->pluck('phase_code')->all())
        ->toBe(['p2', 'p3', 'p4']);
});

test('validation rejects staging markers and legacy codes after remap', function () {
    $table = crewPhaseRemapScratchTable();
    $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;

    DB::table($table)->insert(['phase_code' => 'p2a']);

    $remapper->remapColumn($table, 'phase_code');

    $remapper->assertOnlyAllowedPhaseCodes($table, 'phase_code');

    DB::table($table)->insert(['phase_code' => 'p2a']);

    expect(fn () => $remapper->assertOnlyAllowedPhaseCodes($table, 'phase_code'))
        ->toThrow(RuntimeException::class, 'Invalid crew phase codes remain');
});

test('fresh migrated database uses only finalized six phase codes on assignment phases', function () {
    $invalid = DB::table('crew_assignment_phases')
        ->whereNotIn('phase_code', CrewSixPhaseLifecyclePhaseCodeRemap::ALLOWED_PHASE_CODES)
        ->count();

    expect($invalid)->toBe(0);
});

test('fresh migrated database records six phase remap completion marker', function () {
    $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;

    expect($remapper->isComplete())->toBeTrue()
        ->and(Schema::hasTable(CrewSixPhaseLifecyclePhaseCodeRemap::STATE_TABLE))->toBeTrue();
});
