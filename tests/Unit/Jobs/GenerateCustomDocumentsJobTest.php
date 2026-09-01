<?php

test('generation job quotes mysql reserved aliases when counting run item statuses', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Jobs/GenerateCustomDocumentsJob.php');

    expect($source)->toContain('as `generated`')
        ->and($source)->not->toMatch('/as generated,/');
});
