<?php

declare(strict_types=1);

use App\Models\ServiceToken;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

test('dry-run reports token count and keeps all personal_access_tokens and service_tokens', function (): void {
    $user = User::factory()->create();
    $firstToken = $user->createToken('browser-session-1');
    $secondToken = $user->createToken('browser-session-2');
    $serviceToken = ServiceToken::factory()->create(['name' => 'ci-runner']);

    $this->artisan('auth:purge-legacy-tokens --dry-run')
        ->expectsOutputToContain('Found 2 legacy personal access token(s) in personal_access_tokens.')
        ->expectsOutputToContain('Dry-run mode: no tokens were deleted.')
        ->doesntExpectOutputToContain($firstToken->plainTextToken)
        ->doesntExpectOutputToContain($firstToken->accessToken->token)
        ->doesntExpectOutputToContain($secondToken->plainTextToken)
        ->doesntExpectOutputToContain($secondToken->accessToken->token)
        ->assertExitCode(0);

    expect(PersonalAccessToken::query()->count())->toBe(2);
    expect(ServiceToken::query()->whereKey($serviceToken->id)->exists())->toBeTrue();
});

test('declining confirmation prompt keeps all rows and exits with failure', function (): void {
    $user = User::factory()->create();
    $user->createToken('browser-session-1');
    $user->createToken('browser-session-2');
    $serviceToken = ServiceToken::factory()->create(['name' => 'automation-key']);

    $this->artisan('auth:purge-legacy-tokens')
        ->expectsOutputToContain('Found 2 legacy personal access token(s) in personal_access_tokens.')
        ->expectsConfirmation(
            'Are you sure you want to delete all legacy personal access tokens? This action cannot be undone.',
            'no'
        )
        ->expectsOutputToContain('Aborted: no legacy personal access tokens were deleted.')
        ->assertExitCode(1);

    expect(PersonalAccessToken::query()->count())->toBe(2);
    expect(ServiceToken::query()->whereKey($serviceToken->id)->exists())->toBeTrue();
});

test('non-interactive execution without force keeps all rows and exits with failure', function (): void {
    $user = User::factory()->create();
    $user->createToken('browser-session-1');
    $serviceToken = ServiceToken::factory()->create(['name' => 'ci-token']);

    $this->artisan('auth:purge-legacy-tokens', ['--no-interaction' => true])
        ->expectsOutputToContain('Found 1 legacy personal access token(s) in personal_access_tokens.')
        ->expectsOutputToContain('Non-interactive session detected. Re-run with --force')
        ->assertExitCode(1);

    expect(PersonalAccessToken::query()->count())->toBe(1);
    expect(ServiceToken::query()->whereKey($serviceToken->id)->exists())->toBeTrue();
});

test('force option deletes all personal_access_tokens, prints deleted count, and leaves service_tokens untouched', function (): void {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $patOne = $firstUser->createToken('legacy-web-1');
    $patTwo = $firstUser->createToken('legacy-web-2');
    $patThree = $secondUser->createToken('legacy-web-3');

    $serviceTokenOne = ServiceToken::generate('daemon-sync', ['*'])['token'];
    $serviceTokenTwo = ServiceToken::factory()->create(['name' => 'monitoring-probe']);

    $this->artisan('auth:purge-legacy-tokens --force')
        ->expectsOutputToContain('Found 3 legacy personal access token(s) in personal_access_tokens.')
        ->expectsOutputToContain('Deleted 3 legacy personal access token(s).')
        ->doesntExpectOutputToContain($patOne->plainTextToken)
        ->doesntExpectOutputToContain($patOne->accessToken->token)
        ->doesntExpectOutputToContain($patTwo->plainTextToken)
        ->doesntExpectOutputToContain($patTwo->accessToken->token)
        ->doesntExpectOutputToContain($patThree->plainTextToken)
        ->doesntExpectOutputToContain($patThree->accessToken->token)
        ->assertExitCode(0);

    expect(PersonalAccessToken::query()->count())->toBe(0);
    expect(ServiceToken::query()->count())->toBe(2);
    expect(ServiceToken::query()->whereKey($serviceTokenOne->id)->exists())->toBeTrue();
    expect(ServiceToken::query()->whereKey($serviceTokenTwo->id)->exists())->toBeTrue();
});

test('confirming prompt deletes all personal_access_tokens and leaves service_tokens untouched', function (): void {
    $user = User::factory()->create();
    $user->createToken('legacy-web-1');
    $serviceToken = ServiceToken::factory()->create(['name' => 'backup-agent']);

    $this->artisan('auth:purge-legacy-tokens')
        ->expectsOutputToContain('Found 1 legacy personal access token(s) in personal_access_tokens.')
        ->expectsConfirmation(
            'Are you sure you want to delete all legacy personal access tokens? This action cannot be undone.',
            'yes'
        )
        ->expectsOutputToContain('Deleted 1 legacy personal access token(s).')
        ->assertExitCode(0);

    expect(PersonalAccessToken::query()->count())->toBe(0);
    expect(ServiceToken::query()->whereKey($serviceToken->id)->exists())->toBeTrue();
});
