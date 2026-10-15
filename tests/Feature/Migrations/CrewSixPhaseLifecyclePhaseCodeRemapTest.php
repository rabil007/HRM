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

    $remapper->remapColumn($table, 'phase_code');

    $codes = DB::table($table)->orderBy('id')->pluck('phase_code')->all();

    expect($codes)->toBe(['p0', 'p1a', 'p1b', 'p2', 'p3', 'p4']);
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
        ['phase_code' => 'p3'],
    ]);

    expect(fn () => $remapper->remapColumn($table, 'phase_code'))
        ->toThrow(RuntimeException::class, 'Ready to Join')
        ->and(DB::table($table)->where('phase_code', CrewSixPhaseLifecyclePhaseCodeRemap::TEMP_READY)->count())
        ->toBe(1)
        ->and(DB::table($table)->where('phase_code', 'p1a')->count())
        ->toBe(0);
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
