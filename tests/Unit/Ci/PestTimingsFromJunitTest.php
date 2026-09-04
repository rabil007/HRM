<?php

require_once dirname(__DIR__, 3).'/.github/scripts/ci.php';

test('pest timings from junit aggregate duration by test file', function () {
    $junit = sys_get_temp_dir().'/oms-ci-junit-'.uniqid().'.xml';
    $output = sys_get_temp_dir().'/oms-ci-timings-'.uniqid().'.json';

    file_put_contents($junit, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="phpunit.xml" tests="3" time="12">
    <testsuite name="Tests\Feature\HeavyTest" file="tests/Feature/HeavyTest.php" tests="2" time="9.5">
      <testcase name="one" file="tests/Feature/HeavyTest.php::one" time="5"/>
      <testcase name="two" file="tests/Feature/HeavyTest.php::two" time="4.5"/>
    </testsuite>
    <testsuite name="Tests\Unit\LightTest" file="tests/Unit/LightTest.php" tests="1" time="0.5">
      <testcase name="three" file="tests/Unit/LightTest.php::three" time="0.5"/>
    </testsuite>
  </testsuite>
</testsuites>
XML);

    $timings = oms_ci_pest_timings_from_junit($junit);

    expect($timings['files']['tests/Feature/HeavyTest.php'])->toBe(9.5)
        ->and($timings['files']['tests/Unit/LightTest.php'])->toBe(0.5);

    $root = dirname(__DIR__, 3);
    $command = sprintf(
        'php %s pest-timings-from-junit --junit=%s --output=%s',
        escapeshellarg($root.'/.github/scripts/ci.php'),
        escapeshellarg($junit),
        escapeshellarg($output),
    );

    exec($command, $stdout, $exitCode);

    expect($exitCode)->toBe(0)
        ->and((string) file_get_contents($output))->toContain('"tests/Feature/HeavyTest.php": 9.5');

    unlink($junit);
    unlink($output);
});
