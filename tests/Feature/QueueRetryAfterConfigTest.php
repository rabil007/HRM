<?php

test('redis and database queue retry_after settings are independent', function () {
    $previousRedis = $_ENV['REDIS_QUEUE_RETRY_AFTER'] ?? false;
    $previousDatabase = $_ENV['DB_QUEUE_RETRY_AFTER'] ?? false;

    try {
        $_ENV['REDIS_QUEUE_RETRY_AFTER'] = '777';
        $_SERVER['REDIS_QUEUE_RETRY_AFTER'] = '777';
        putenv('REDIS_QUEUE_RETRY_AFTER=777');

        $_ENV['DB_QUEUE_RETRY_AFTER'] = '888';
        $_SERVER['DB_QUEUE_RETRY_AFTER'] = '888';
        putenv('DB_QUEUE_RETRY_AFTER=888');

        $config = require config_path('queue.php');

        expect($config['connections']['redis']['retry_after'])->toBe(777)
            ->and($config['connections']['database']['retry_after'])->toBe(888)
            ->and($config['connections']['redis']['retry_after'])
            ->not->toBe($config['connections']['database']['retry_after']);
    } finally {
        if ($previousRedis === false) {
            putenv('REDIS_QUEUE_RETRY_AFTER');
            unset($_ENV['REDIS_QUEUE_RETRY_AFTER'], $_SERVER['REDIS_QUEUE_RETRY_AFTER']);
        } else {
            $_ENV['REDIS_QUEUE_RETRY_AFTER'] = $previousRedis;
            $_SERVER['REDIS_QUEUE_RETRY_AFTER'] = $previousRedis;
            putenv('REDIS_QUEUE_RETRY_AFTER='.$previousRedis);
        }

        if ($previousDatabase === false) {
            putenv('DB_QUEUE_RETRY_AFTER');
            unset($_ENV['DB_QUEUE_RETRY_AFTER'], $_SERVER['DB_QUEUE_RETRY_AFTER']);
        } else {
            $_ENV['DB_QUEUE_RETRY_AFTER'] = $previousDatabase;
            $_SERVER['DB_QUEUE_RETRY_AFTER'] = $previousDatabase;
            putenv('DB_QUEUE_RETRY_AFTER='.$previousDatabase);
        }
    }
});

test('redis queue retry_after defaults from REDIS_QUEUE_RETRY_AFTER', function () {
    $configSource = file_get_contents(config_path('queue.php'));

    expect($configSource)->toContain("env('REDIS_QUEUE_RETRY_AFTER', 660)")
        ->and($configSource)->toContain("env('DB_QUEUE_RETRY_AFTER', 660)")
        ->and($configSource)->not->toMatch("/'redis' => \\[[\\s\\S]*env\\('DB_QUEUE_RETRY_AFTER'/");
});
