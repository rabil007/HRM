<?php

use App\Models\CrewAssignment;
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

test('scheduled movement payloads remap unambiguous legacy phase codes on unresolved rows only', function () {
    ['user' => $user, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();
    $now = now();
    $rows = [
        'demob' => [
            'movement_action' => 'confirm_disembarkation',
            'status' => 'scheduled',
            'action_payload' => [
                'next_phase' => 'p5',
                'accommodation_status' => 'hotel',
                'hotel_id' => 9,
                'remarks' => 'keep demob hotel',
            ],
        ],
        'legacy_home' => [
            'movement_action' => 'confirm_disembarkation',
            'status' => 'needs_attention',
            'action_payload' => [
                'next_phase' => 'p6',
                'remarks' => 'direct home',
            ],
        ],
        'modern_home' => [
            'movement_action' => 'confirm_disembarkation',
            'status' => 'scheduled',
            'action_payload' => [
                'next_phase' => 'p4',
                'remarks' => 'already modern home',
            ],
        ],
        'training' => [
            'movement_action' => 'complete_training',
            'status' => 'processing',
            'action_payload' => [
                'next_phase' => 'p2a',
                'course_id' => 4,
            ],
        ],
        'redeploy' => [
            'movement_action' => 'redeploy',
            'status' => 'scheduled',
            'action_payload' => [
                'starting_phase' => 'p4',
                'next_phase' => 'p2a',
                'vessel_id' => 12,
            ],
        ],
        'join' => [
            'movement_action' => 'join_vessel',
            'status' => 'scheduled',
            'action_payload' => [
                'next_phase' => 'p4',
                'vessel_id' => 3,
            ],
        ],
        'executed_audit' => [
            'movement_action' => 'confirm_disembarkation',
            'status' => 'executed',
            'action_payload' => [
                'next_phase' => 'p5',
                'remarks' => 'historical audit',
            ],
        ],
        'cancelled_audit' => [
            'movement_action' => 'join_vessel',
            'status' => 'cancelled',
            'action_payload' => [
                'next_phase' => 'p4',
                'remarks' => 'cancelled legacy join',
            ],
        ],
    ];

    $ids = [];

    foreach ($rows as $row) {
        // One unresolved schedule per assignment (partial unique index on unresolved_assignment_id).
        $target = in_array($row['status'], ['scheduled', 'processing', 'needs_attention'], true)
            ? CrewAssignment::factory()->forEmployee($employee)->create()
            : $assignment;

        $ids[] = DB::table('crew_scheduled_movements')->insertGetId([
            'company_id' => $target->company_id,
            'crew_assignment_id' => $target->id,
            'employee_id' => $target->employee_id,
            'movement_action' => $row['movement_action'],
            'action_payload' => json_encode($row['action_payload'], JSON_THROW_ON_ERROR),
            'scheduled_at' => $now,
            'scheduled_timezone' => 'UTC',
            'status' => $row['status'],
            'created_by' => $user->id,
            'execution_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;
    $remapper->remapScheduledMovementPayloadPhaseCodes();
    $remapper->remapScheduledMovementPayloadPhaseCodes();

    $payload = function (int $id): array {
        $raw = DB::table('crew_scheduled_movements')->where('id', $id)->value('action_payload');

        return is_string($raw) ? json_decode($raw, true) : (array) $raw;
    };

    expect($payload($ids[0]))->toMatchArray([
        'next_phase' => 'p3',
        'accommodation_status' => 'hotel',
        'hotel_id' => 9,
        'remarks' => 'keep demob hotel',
    ])
        ->and($payload($ids[1])['next_phase'])->toBe('p4')
        ->and($payload($ids[2])['next_phase'])->toBe('p4')
        ->and($payload($ids[2])['remarks'])->toBe('already modern home')
        ->and($payload($ids[3]))->toMatchArray([
            'next_phase' => 'p1a',
            'course_id' => 4,
        ])
        ->and($payload($ids[4]))->toMatchArray([
            'starting_phase' => 'p2',
            'next_phase' => 'p1a',
            'vessel_id' => 12,
        ])
        ->and($payload($ids[5]))->toMatchArray([
            'next_phase' => 'p2',
            'vessel_id' => 3,
        ])
        ->and($payload($ids[6])['next_phase'])->toBe('p5')
        ->and($payload($ids[6])['remarks'])->toBe('historical audit')
        ->and($payload($ids[7])['next_phase'])->toBe('p4')
        ->and($payload($ids[7])['remarks'])->toBe('cancelled legacy join');
});
