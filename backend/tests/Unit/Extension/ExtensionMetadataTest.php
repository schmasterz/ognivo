<?php

declare(strict_types=1);

use App\Services\Extension\ExtensionMetadata;

it('parses a valid array manifest into ExtensionMetadata', function (): void {
    $metadata = ExtensionMetadata::fromArray([
        'name' => 'ognivo/stripe-billing',
        'version' => '1.2.3',
        'description' => 'Stripe billing integration for Ognivo',
        'author' => 'Ognivo Team',
        'ognivo_version_constraint' => '^1.0',
        'providers' => ['Ognivo\\StripeBilling\\StripeBillingServiceProvider'],
        'event_subscribers' => ['Ognivo\\StripeBilling\\Subscribers\\UsageSubscriber'],
    ]);

    expect($metadata)->toBeInstanceOf(ExtensionMetadata::class)
        ->and($metadata->name)->toBe('ognivo/stripe-billing')
        ->and($metadata->version)->toBe('1.2.3')
        ->and($metadata->description)->toBe('Stripe billing integration for Ognivo')
        ->and($metadata->author)->toBe('Ognivo Team')
        ->and($metadata->ognivoVersionConstraint)->toBe('^1.0')
        ->and($metadata->providers)->toBe(['Ognivo\\StripeBilling\\StripeBillingServiceProvider'])
        ->and($metadata->eventSubscribers)->toBe(['Ognivo\\StripeBilling\\Subscribers\\UsageSubscriber']);
});

it('parses a valid JSON manifest into ExtensionMetadata with defaults for optional fields', function (): void {
    $json = json_encode([
        'name' => 'ognivo/cloudflare-dns',
        'version' => '0.4.0',
        'ognivo_version_constraint' => '^1.0',
    ], JSON_THROW_ON_ERROR);

    $metadata = ExtensionMetadata::fromJson($json);

    expect($metadata->name)->toBe('ognivo/cloudflare-dns')
        ->and($metadata->version)->toBe('0.4.0')
        ->and($metadata->description)->toBe('')
        ->and($metadata->author)->toBe('')
        ->and($metadata->ognivoVersionConstraint)->toBe('^1.0')
        ->and($metadata->providers)->toBe([])
        ->and($metadata->eventSubscribers)->toBe([]);
});

it('throws InvalidArgumentException when name is missing or empty', function (array $payload): void {
    expect(fn (): ExtensionMetadata => ExtensionMetadata::fromArray($payload))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing name' => [[
        'version' => '1.0.0',
        'ognivo_version_constraint' => '^1.0',
    ]],
    'empty name' => [[
        'name' => '   ',
        'version' => '1.0.0',
        'ognivo_version_constraint' => '^1.0',
    ]],
]);

it('throws InvalidArgumentException when version is missing or invalid', function (array $payload): void {
    expect(fn (): ExtensionMetadata => ExtensionMetadata::fromArray($payload))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing version' => [[
        'name' => 'ognivo/test-ext',
        'ognivo_version_constraint' => '^1.0',
    ]],
    'invalid semver version' => [[
        'name' => 'ognivo/test-ext',
        'version' => 'not-a-valid-version',
        'ognivo_version_constraint' => '^1.0',
    ]],
]);

it('throws InvalidArgumentException when ognivo_version_constraint is missing or invalid', function (array $payload): void {
    expect(fn (): ExtensionMetadata => ExtensionMetadata::fromArray($payload))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing constraint' => [[
        'name' => 'ognivo/test-ext',
        'version' => '1.0.0',
    ]],
    'invalid constraint' => [[
        'name' => 'ognivo/test-ext',
        'version' => '1.0.0',
        'ognivo_version_constraint' => 'not-a-constraint',
    ]],
]);

it('throws InvalidArgumentException on malformed JSON or non-object JSON', function (string $json): void {
    expect(fn (): ExtensionMetadata => ExtensionMetadata::fromJson($json))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'malformed json' => ['{invalid-json'],
    'scalar json' => ['"just a string"'],
]);

it('throws InvalidArgumentException when providers or event_subscribers contain invalid values', function (array $payload): void {
    expect(fn (): ExtensionMetadata => ExtensionMetadata::fromArray($payload))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'non-array providers' => [[
        'name' => 'ognivo/test-ext',
        'version' => '1.0.0',
        'ognivo_version_constraint' => '^1.0',
        'providers' => 'App\\Provider',
    ]],
    'empty entry in event_subscribers' => [[
        'name' => 'ognivo/test-ext',
        'version' => '1.0.0',
        'ognivo_version_constraint' => '^1.0',
        'event_subscribers' => [''],
    ]],
]);

it('checks core version compatibility using semver constraints', function (): void {
    $compatible = ExtensionMetadata::fromArray([
        'name' => 'ognivo/compat-ext',
        'version' => '1.0.0',
        'ognivo_version_constraint' => '^1.0',
    ]);

    $incompatible = ExtensionMetadata::fromArray([
        'name' => 'ognivo/future-ext',
        'version' => '2.0.0',
        'ognivo_version_constraint' => '^2.0',
    ]);

    expect($compatible->isCompatibleWith('1.0.0'))->toBeTrue()
        ->and($compatible->isCompatibleWith('1.5.2'))->toBeTrue()
        ->and($compatible->isCompatibleWith('2.0.0'))->toBeFalse()
        ->and($incompatible->isCompatibleWith('1.0.0'))->toBeFalse()
        ->and($incompatible->isCompatibleWith('2.1.0'))->toBeTrue();
});
