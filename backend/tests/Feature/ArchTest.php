<?php

declare(strict_types=1);

test('arch: legacy ip address model does not exist', function () {
    expect(class_exists('App\\Models\\Ip'.'Address'))->toBeFalse();
    expect(class_exists('App\\Http\\Resources\\Ip'.'AddressResource'))->toBeFalse();
    expect(class_exists('Database\\Factories\\Ip'.'AddressFactory'))->toBeFalse();
});

test('arch: forbidden legacy ipam identifiers do not appear in app codebase', function () {
    $forbidden = [
        'is_'.'floating',
        'ptr_'.'record',
        'anti_'.'spoofing',
        'ip'.'Addresses',
        'ip_pool_'.'id',
    ];

    $appPath = app_path();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $violations = [];

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        foreach ($forbidden as $identifier) {
            if (str_contains($content, $identifier)) {
                $violations[] = sprintf('%s contains forbidden identifier "%s"', $file->getFilename(), $identifier);
            }
        }
    }

    expect($violations)->toBeEmpty();
});
