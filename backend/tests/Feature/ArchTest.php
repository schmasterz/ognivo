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

test('arch: direct status writes are forbidden outside App\\States', function () {
    $appPath = str_replace('\\', '/', app_path());
    $statesPath = $appPath.'/States/';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $violations = [];

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        if (str_starts_with($path, $statesPath)) {
            continue;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }

        $relativePath = ltrim(substr($path, strlen($appPath)), '/');

        if (preg_match('/DB::table\(\s*[\'"]vps_plans[\'"]\s*\)[^;]*->update\s*\(/s', $content) === 1) {
            $violations[] = sprintf('%s performs direct DB::table(vps_plans)->update()', $relativePath);
        }

        if (preg_match('/DB::table\(\s*[\'"]vps_plan_versions[\'"]\s*\)[^;]*->update\s*\(/s', $content) === 1) {
            $violations[] = sprintf('%s performs direct DB::table(vps_plan_versions)->update()', $relativePath);
        }

        if (preg_match('/DB::table\(\s*[\'"]vps_plan_changes[\'"]\s*\)[^;]*->update\s*\(/s', $content) === 1) {
            $violations[] = sprintf('%s performs direct DB::table(vps_plan_changes)->update()', $relativePath);
        }

        if (preg_match_all('/->update\(\s*\[[^\]]*[\'"]status[\'"]\s*=>[^\]]*\]/s', $content, $matches) > 0) {
            foreach ($matches[0] as $match) {
                if (! str_contains($match, 'IpAllocationStatus')) {
                    $violations[] = sprintf('%s performs direct ->update([..., status => ...])', $relativePath);
                }
            }
        }

        if (preg_match('/->forceFill\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s', $content) === 1) {
            $violations[] = sprintf('%s performs direct ->forceFill([..., status => ...])', $relativePath);
        }

        if (str_contains($content, 'VpsPlanChange') || str_contains($content, 'vps_plan_changes')) {
            if (preg_match('/->fill\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s', $content) === 1) {
                $violations[] = sprintf('%s performs direct ->fill([..., status => ...]) on VpsPlanChange', $relativePath);
            }

            if (preg_match('/\$(?!this\b)[a-zA-Z_][a-zA-Z0-9_]*->status\s*=(?![=>])/s', $content) === 1) {
                $violations[] = sprintf('%s assigns ->status directly on a model outside App\\States', $relativePath);
            }
        }
    }

    expect($violations)->toBeEmpty();
});

test('arch: direct writes or deletes on vps_plan_versions are forbidden outside VpsPlanVersionService', function () {
    $appPath = str_replace('\\', '/', app_path());
    $soleWriter = $appPath.'/Services/Vps/VpsPlanVersionService.php';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $violations = [];

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        if ($path === $soleWriter) {
            continue;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }

        $relativePath = ltrim(substr($path, strlen($appPath)), '/');

        if (preg_match('/DB::table\(\s*[\'"]vps_plan_versions[\'"]\s*\)[^;]*->(?:insert|update|upsert|delete)\s*\(/s', $content) === 1) {
            $violations[] = sprintf('%s writes/deletes vps_plan_versions directly via DB::table()', $relativePath);
        }

        if (preg_match('/VpsPlanVersion\s*::[^;]*?(?:->update|->delete|::create|::insert|::upsert|::destroy)\s*\(/s', $content) === 1) {
            $violations[] = sprintf('%s writes/deletes VpsPlanVersion directly', $relativePath);
        }

        if (preg_match('/->(?:versions|draftVersion)\(\)[^;]*->(?:create|insert|update|upsert|delete|destroy)\s*\(/s', $content) === 1) {
            $violations[] = sprintf('%s mutates vps_plan_versions via relation outside VpsPlanVersionService', $relativePath);
        }
    }

    expect($violations)->toBeEmpty();
});
