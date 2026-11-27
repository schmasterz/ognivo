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

/**
 * Collect all PHP files under the given directory with normalized relative paths.
 *
 * @return list<array{path: string, relative: string, filename: string}>
 */
$collectPhpFiles = static function (string $directory): array {
    $basePath = str_replace('\\', '/', $directory);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $files = [];

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $files[] = [
            'path' => $path,
            'relative' => ltrim(substr($path, strlen($basePath)), '/'),
            'filename' => $file->getFilename(),
        ];
    }

    usort($files, static fn (array $a, array $b): int => $a['relative'] <=> $b['relative']);

    return $files;
};

/**
 * Verify that a FQCN resolves to a final readonly concrete class.
 *
 * @return list<string>
 */
$inspectFinalReadonlyClass = static function (string $relative, string $fqcn): array {
    if (enum_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || ! class_exists($fqcn)) {
        return [sprintf('%s (%s) must declare a concrete class', $relative, $fqcn)];
    }

    $reflection = new ReflectionClass($fqcn);
    $violations = [];

    if (! $reflection->isFinal()) {
        $violations[] = sprintf('%s (%s) must be final', $relative, $fqcn);
    }

    if (! $reflection->isReadOnly()) {
        $violations[] = sprintf('%s (%s) must be readonly', $relative, $fqcn);
    }

    return $violations;
};

/**
 * Assert that every PHP file in a directory resolves to a final readonly concrete class.
 *
 * @return list<string>
 */
$assertFinalReadonlyClasses = static function (string $directory, string $baseNamespace) use ($collectPhpFiles, $inspectFinalReadonlyClass): array {
    $violations = [];

    foreach ($collectPhpFiles($directory) as $entry) {
        $relative = $entry['relative'];
        $fqcn = $baseNamespace.'\\'.str_replace('/', '\\', substr($relative, 0, -4));

        array_push($violations, ...$inspectFinalReadonlyClass($relative, $fqcn));
    }

    return $violations;
};

test('arch: app/DTO contains only domain-grouped final readonly *DTO classes', function () use ($collectPhpFiles, $inspectFinalReadonlyClass): void {
    $violations = [];

    foreach ($collectPhpFiles(app_path('DTO')) as $entry) {
        $relative = $entry['relative'];

        if (! str_contains($relative, '/')) {
            $violations[] = sprintf('DTO/%s must reside inside a domain subdirectory', $relative);
        }

        if (! str_ends_with($entry['filename'], 'DTO.php')) {
            $violations[] = sprintf('DTO/%s filename must end with DTO.php', $relative);
        }

        $fqcn = 'App\\DTO\\'.str_replace('/', '\\', substr($relative, 0, -4));
        array_push($violations, ...$inspectFinalReadonlyClass('DTO/'.$relative, $fqcn));
    }

    expect($violations)->toBeEmpty();
});

test('arch: app/Enums contains only native PHP enums', function () use ($collectPhpFiles): void {
    $violations = [];

    foreach ($collectPhpFiles(app_path('Enums')) as $entry) {
        $relative = $entry['relative'];
        $fqcn = 'App\\Enums\\'.str_replace('/', '\\', substr($relative, 0, -4));

        if (! enum_exists($fqcn)) {
            $violations[] = sprintf('Enums/%s (%s) must declare a native PHP enum', $relative, $fqcn);
        }
    }

    expect($violations)->toBeEmpty();
});

test('arch: app/ValueObjects and app/Contracts/Data contain only final readonly classes, and app/Contracts root contains only interfaces', function () use ($collectPhpFiles, $assertFinalReadonlyClasses): void {
    $violations = array_merge(
        $assertFinalReadonlyClasses(app_path('ValueObjects'), 'App\\ValueObjects'),
        $assertFinalReadonlyClasses(app_path('Contracts/Data'), 'App\\Contracts\\Data'),
    );

    foreach ($collectPhpFiles(app_path('Contracts')) as $entry) {
        $relative = $entry['relative'];

        if (str_starts_with($relative, 'Data/')) {
            continue;
        }

        if (str_contains($relative, '/')) {
            $violations[] = sprintf('Contracts/%s must reside directly in app/Contracts/ or app/Contracts/Data/', $relative);

            continue;
        }

        $fqcn = 'App\\Contracts\\'.substr($relative, 0, -4);
        if (! interface_exists($fqcn)) {
            $violations[] = sprintf('Contracts/%s (%s) must declare a PHP interface', $relative, $fqcn);
        }
    }

    expect($violations)->toBeEmpty();
});

test('arch: DTOs and ValueObjects remain decoupled from state machines and service container helpers', function () use ($collectPhpFiles): void {
    $directories = [
        'DTO' => app_path('DTO'),
        'ValueObjects' => app_path('ValueObjects'),
    ];

    $ignoredPrecedingTokens = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_FUNCTION,
        T_CONST,
        T_CLASS,
        T_NEW,
    ];

    $violations = [];

    foreach ($directories as $label => $directory) {
        foreach ($collectPhpFiles($directory) as $entry) {
            $content = file_get_contents($entry['path']);
            if ($content === false) {
                continue;
            }

            $tokens = token_get_all($content);
            $significantTokens = [];
            $strippedSource = '';

            foreach ($tokens as $token) {
                if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                    continue;
                }

                $strippedSource .= is_array($token) ? $token[1] : $token;

                if (is_array($token) && $token[0] === T_WHITESPACE) {
                    continue;
                }

                $significantTokens[] = $token;
            }

            if (str_contains($strippedSource, 'App\\States\\') || str_contains($strippedSource, 'App\\\\States\\\\')) {
                $violations[] = sprintf('%s/%s references App\\States\\', $label, $entry['relative']);
            }

            $tokenCount = count($significantTokens);
            for ($i = 0; $i < $tokenCount; $i++) {
                $token = $significantTokens[$i];
                if (! is_array($token)) {
                    continue;
                }

                if ($token[0] !== T_STRING && $token[0] !== T_NAME_FULLY_QUALIFIED) {
                    continue;
                }

                $name = ltrim(strtolower($token[1]), '\\');
                if ($name !== 'app' && $name !== 'config') {
                    continue;
                }

                $next = $significantTokens[$i + 1] ?? null;
                if ($next !== '(') {
                    continue;
                }

                $prev = $significantTokens[$i - 1] ?? null;
                if ($token[0] === T_STRING && is_array($prev) && in_array($prev[0], $ignoredPrecedingTokens, true)) {
                    continue;
                }

                $violations[] = sprintf('%s/%s calls %s() helper', $label, $entry['relative'], $name);
            }
        }
    }

    expect($violations)->toBeEmpty();
});
