<?php

declare(strict_types=1);

use App\Http\Middleware\IdempotencyMiddleware;
use App\Models\IdempotencyKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->executionCount = 0;

    Route::middleware(IdempotencyMiddleware::class)
        ->post('/_test/idempotent', function (Request $request) {
            $this->executionCount++;

            return response()->json([
                'count' => $this->executionCount,
                'name' => $request->input('name'),
            ], 201)->header('X-Custom-Header', 'original-value');
        });
});

it('passes through requests without an Idempotency-Key header normally', function (): void {
    $first = $this->postJson('/_test/idempotent', ['name' => 'vm-alpha']);
    $second = $this->postJson('/_test/idempotent', ['name' => 'vm-alpha']);

    $first->assertStatus(201)
        ->assertJson(['count' => 1, 'name' => 'vm-alpha'])
        ->assertHeaderMissing('X-Idempotent-Replay');

    $second->assertStatus(201)
        ->assertJson(['count' => 2, 'name' => 'vm-alpha'])
        ->assertHeaderMissing('X-Idempotent-Replay');

    expect($this->executionCount)->toBe(2)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});

it('executes the first request with an Idempotency-Key and stores the response', function (): void {
    $response = $this->withHeader('Idempotency-Key', 'idem-key-001')
        ->postJson('/_test/idempotent', ['name' => 'vm-one']);

    $response->assertStatus(201)
        ->assertJson(['count' => 1, 'name' => 'vm-one'])
        ->assertHeader('X-Custom-Header', 'original-value')
        ->assertHeaderMissing('X-Idempotent-Replay');

    $record = IdempotencyKey::query()->where('key', 'idem-key-001')->first();

    expect($record)->not->toBeNull()
        ->and($record?->response_status)->toBe(201)
        ->and($record?->locked_at)->toBeNull()
        ->and($record?->isCompleted())->toBeTrue()
        ->and($record?->response_body)->toContain('vm-one')
        ->and($this->executionCount)->toBe(1);
});

it('replays the exact cached response with X-Idempotent-Replay header on duplicate requests', function (): void {
    $first = $this->withHeader('Idempotency-Key', 'idem-key-002')
        ->postJson('/_test/idempotent', ['name' => 'vm-two']);

    $first->assertStatus(201)
        ->assertJson(['count' => 1, 'name' => 'vm-two']);

    $second = $this->withHeader('Idempotency-Key', 'idem-key-002')
        ->postJson('/_test/idempotent', ['name' => 'vm-two']);

    $second->assertStatus(201)
        ->assertJson(['count' => 1, 'name' => 'vm-two'])
        ->assertHeader('X-Custom-Header', 'original-value')
        ->assertHeader('X-Idempotent-Replay', 'true');

    expect($this->executionCount)->toBe(1);
});

it('returns 422 Unprocessable Entity when the same Idempotency-Key is reused with a different payload', function (): void {
    $this->withHeader('Idempotency-Key', 'idem-key-003')
        ->postJson('/_test/idempotent', ['name' => 'vm-original'])
        ->assertStatus(201);

    $mismatch = $this->withHeader('Idempotency-Key', 'idem-key-003')
        ->postJson('/_test/idempotent', ['name' => 'vm-modified']);

    $mismatch->assertStatus(422)
        ->assertJson([
            'message' => 'Idempotency key payload mismatch',
        ]);

    expect($this->executionCount)->toBe(1);
});

it('returns 409 Conflict when an in-flight request holds an active lock for the Idempotency-Key', function (): void {
    $payload = json_encode(['name' => 'vm-concurrent'], JSON_THROW_ON_ERROR);
    $checksum = hash('sha256', 'POST|_test/idempotent|'.$payload);

    IdempotencyKey::query()->create([
        'key' => 'idem-key-004',
        'request_hash' => $checksum,
        'locked_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);

    $conflict = $this->withHeader('Idempotency-Key', 'idem-key-004')
        ->postJson('/_test/idempotent', ['name' => 'vm-concurrent']);

    $conflict->assertStatus(409)
        ->assertJson([
            'message' => 'A request with this idempotency key is currently being processed.',
        ]);

    expect($this->executionCount)->toBe(0);
});
