<?php

declare(strict_types=1);

use App\Models\Host;
use App\Models\Image;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Vm\Deleted;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    config(['app.allow_fake_driver' => true]);
    HypervisorManager::fake(new FakeHypervisorDriver);

    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $customerRole = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole($adminRole);

    $this->customer = User::factory()->create();
    $this->customer->assignRole($customerRole);
});

afterEach(function (): void {
    HypervisorManager::resetFake();
});

test('POST /api/admin/hosts default attaches all catalog images and sets auto_attach_images to true', function (): void {
    $img1 = Image::factory()->create();
    $img2 = Image::factory()->create();

    $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/hosts', [
        'name' => 'Default Host Node',
        'endpoint_url' => 'https://10.10.1.1:8443',
        'driver' => 'fake',
    ]);

    $response->assertStatus(201);
    $hostId = (int) $response->json('host.id');
    expect($response->json('host.auto_attach_images'))->toBeTrue();

    $attachedIds = DB::table('host_image')->where('host_id', $hostId)->pluck('image_id')->all();
    expect($attachedIds)->toContain($img1->id, $img2->id);

    $host = Host::find($hostId);
    expect($host)->not->toBeNull()
        ->and($host->auto_attach_images)->toBeTrue();
});

test('POST /api/admin/hosts with selected mode and auto_attach_images false attaches only specified IDs', function (): void {
    $img1 = Image::factory()->create();
    $img2 = Image::factory()->create();
    $img3 = Image::factory()->create();

    $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/hosts', [
        'name' => 'Selected Host Node',
        'endpoint_url' => 'https://10.10.1.2:8443',
        'driver' => 'fake',
        'auto_attach_images' => false,
        'images' => [
            'mode' => 'selected',
            'ids' => [$img1->id, $img3->id],
        ],
    ]);

    $response->assertStatus(201);
    $hostId = (int) $response->json('host.id');
    expect($response->json('host.auto_attach_images'))->toBeFalse();

    $attachedIds = DB::table('host_image')->where('host_id', $hostId)->pluck('image_id')->sort()->values()->all();
    $expected = collect([$img1->id, $img3->id])->sort()->values()->all();
    expect($attachedIds)->toBe($expected);

    $host = Host::find($hostId);
    expect($host)->not->toBeNull()
        ->and($host->auto_attach_images)->toBeFalse();
});

test('POST /api/admin/hosts with filter mode attaches matching non-excluded images', function (): void {
    $imgLinux1 = Image::factory()->create(['os_type' => 'linux']);
    $imgLinux2 = Image::factory()->create(['os_type' => 'linux']);
    $imgWindows = Image::factory()->create(['os_type' => 'windows']);

    $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/hosts', [
        'name' => 'Filter Host Node',
        'endpoint_url' => 'https://10.10.1.3:8443',
        'driver' => 'fake',
        'images' => [
            'mode' => 'filter',
            'filter' => ['os_type' => 'linux'],
            'exclude_ids' => [$imgLinux2->id],
        ],
    ]);

    $response->assertStatus(201);
    $hostId = (int) $response->json('host.id');

    $attachedIds = DB::table('host_image')->where('host_id', $hostId)->pluck('image_id')->all();
    expect($attachedIds)->toContain($imgLinux1->id)
        ->and($attachedIds)->not->toContain($imgLinux2->id)
        ->and($attachedIds)->not->toContain($imgWindows->id);
});

test('PUT /api/admin/hosts/{host} with delta mode updates host_image accurately', function (): void {
    $host = Host::factory()->create(['driver' => 'fake', 'auto_attach_images' => true]);
    $img1 = Image::factory()->create(['os_type' => 'linux']);
    $img2 = Image::factory()->create(['os_type' => 'windows']);
    $img3 = Image::factory()->create(['os_type' => 'bsd']);
    $img4 = Image::factory()->create(['os_type' => 'linux']);

    DB::table('host_image')->where('host_id', $host->id)->delete();
    DB::table('host_image')->insert([
        ['host_id' => $host->id, 'image_id' => $img1->id],
        ['host_id' => $host->id, 'image_id' => $img2->id],
    ]);

    $response = $this->actingAs($this->admin)->putJson("/api/v1/admin/hosts/{$host->id}", [
        'name' => $host->name,
        'endpoint_url' => $host->endpoint_url,
        'driver' => 'fake',
        'images' => [
            'mode' => 'delta',
            'detach_ids' => [$img2->id],
            'attach_ids' => [$img3->id],
            'filter_ops' => [
                ['action' => 'attach', 'filter' => ['os_type' => 'linux']],
            ],
        ],
    ]);

    $response->assertStatus(200);

    $attachedIds = DB::table('host_image')->where('host_id', $host->id)->pluck('image_id')->all();
    expect($attachedIds)->toContain($img1->id, $img3->id, $img4->id)
        ->and($attachedIds)->not->toContain($img2->id);
});

test('PUT /api/admin/hosts/{host} with all mode attaches all catalog images', function (): void {
    $host = Host::factory()->create(['driver' => 'fake']);
    $img1 = Image::factory()->create();
    $img2 = Image::factory()->create();
    $img3 = Image::factory()->create();

    DB::table('host_image')->where('host_id', $host->id)->delete();

    $response = $this->actingAs($this->admin)->putJson("/api/v1/admin/hosts/{$host->id}", [
        'name' => $host->name,
        'endpoint_url' => $host->endpoint_url,
        'driver' => 'fake',
        'images' => [
            'mode' => 'all',
        ],
    ]);

    $response->assertStatus(200);

    $attachedIds = DB::table('host_image')->where('host_id', $host->id)->pluck('image_id')->all();
    expect($attachedIds)->toContain($img1->id, $img2->id, $img3->id);
});

test('PUT /api/admin/hosts/{host} validation errors on invalid image payloads', function (): void {
    $host = Host::factory()->create(['driver' => 'fake']);

    // 1. empty ids in selected mode
    $resEmpty = $this->actingAs($this->admin)->putJson("/api/v1/admin/hosts/{$host->id}", [
        'name' => $host->name,
        'endpoint_url' => $host->endpoint_url,
        'driver' => 'fake',
        'images' => [
            'mode' => 'selected',
            'ids' => [],
        ],
    ]);
    $resEmpty->assertStatus(422)
        ->assertJsonValidationErrors(['images.ids']);

    // 2. non-existent image ID
    $resNonExistent = $this->actingAs($this->admin)->putJson("/api/v1/admin/hosts/{$host->id}", [
        'name' => $host->name,
        'endpoint_url' => $host->endpoint_url,
        'driver' => 'fake',
        'images' => [
            'mode' => 'selected',
            'ids' => [999999],
        ],
    ]);
    $resNonExistent->assertStatus(422)
        ->assertJsonValidationErrors(['images.ids']);

    // 3. unknown filter key
    $resUnknownFilter = $this->actingAs($this->admin)->putJson("/api/v1/admin/hosts/{$host->id}", [
        'name' => $host->name,
        'endpoint_url' => $host->endpoint_url,
        'driver' => 'fake',
        'images' => [
            'mode' => 'filter',
            'filter' => ['unknown_key' => 'linux'],
        ],
    ]);
    $resUnknownFilter->assertStatus(422)
        ->assertJsonValidationErrors(['images.filter']);

    // 4. unknown key in images
    $resUnknownKey = $this->actingAs($this->admin)->putJson("/api/v1/admin/hosts/{$host->id}", [
        'name' => $host->name,
        'endpoint_url' => $host->endpoint_url,
        'driver' => 'fake',
        'images' => [
            'mode' => 'all',
            'unknown_field' => 'value',
        ],
    ]);
    $resUnknownKey->assertStatus(422)
        ->assertJsonValidationErrors(['images']);
});

test('updating host attributes, auto_attach_images, and images records exactly one audit log with diff and images context', function (): void {
    $host = Host::factory()->create([
        'name' => 'Original Host',
        'driver' => 'fake',
        'auto_attach_images' => true,
    ]);
    $img1 = Image::factory()->create();
    $img2 = Image::factory()->create();
    $img3 = Image::factory()->create();

    DB::table('host_image')->where('host_id', $host->id)->delete();
    DB::table('host_image')->insert([
        ['host_id' => $host->id, 'image_id' => $img1->id],
        ['host_id' => $host->id, 'image_id' => $img2->id],
    ]);

    DB::table('audit_logs')->where('subject_id', (string) $host->id)->delete();

    $response = $this->actingAs($this->admin)->putJson("/api/v1/admin/hosts/{$host->id}", [
        'name' => 'Renamed Host',
        'endpoint_url' => $host->endpoint_url,
        'driver' => 'fake',
        'auto_attach_images' => false,
        'images' => [
            'mode' => 'delta',
            'attach_ids' => [$img3->id],
            'detach_ids' => [$img1->id],
        ],
    ]);

    $response->assertStatus(200);

    $auditEntries = DB::table('audit_logs')
        ->where('action', 'host.updated')
        ->where('subject_id', (string) $host->id)
        ->get();

    expect($auditEntries)->toHaveCount(1);

    $entry = $auditEntries->first();
    $context = json_decode((string) $entry->context, true);

    expect($context)->toHaveKey('diff')
        ->and($context)->toHaveKey('images')
        ->and($context['diff']['name']['old'])->toBe('Original Host')
        ->and($context['diff']['name']['new'])->toBe('Renamed Host')
        ->and($context['diff']['auto_attach_images']['old'])->toBeTrue()
        ->and($context['diff']['auto_attach_images']['new'])->toBeFalse()
        ->and($context['images']['attached'])->toBe(1)
        ->and($context['images']['detached'])->toBe(1)
        ->and($context['images']['auto_attach_changed'])->toBeTrue();
});

test('updating only images records exactly one audit log with images context and no diff', function (): void {
    $host = Host::factory()->create([
        'name' => 'Static Host',
        'driver' => 'fake',
        'auto_attach_images' => true,
    ]);
    $img1 = Image::factory()->create();
    $img2 = Image::factory()->create();

    DB::table('host_image')->where('host_id', $host->id)->delete();
    DB::table('host_image')->insert([
        ['host_id' => $host->id, 'image_id' => $img1->id],
    ]);

    DB::table('audit_logs')->where('subject_id', (string) $host->id)->delete();

    $response = $this->actingAs($this->admin)->putJson("/api/v1/admin/hosts/{$host->id}", [
        'name' => $host->name,
        'region' => $host->region,
        'endpoint_url' => $host->endpoint_url,
        'driver' => 'fake',
        'auto_attach_images' => true,
        'images' => [
            'mode' => 'delta',
            'attach_ids' => [$img2->id],
        ],
    ]);

    $response->assertStatus(200);

    $auditEntries = DB::table('audit_logs')
        ->where('action', 'host.updated')
        ->where('subject_id', (string) $host->id)
        ->get();

    expect($auditEntries)->toHaveCount(1);

    $entry = $auditEntries->first();
    $context = json_decode((string) $entry->context, true);

    expect($context)->toHaveKey('images')
        ->and($context)->not->toHaveKey('diff')
        ->and($context['images']['attached'])->toBe(1)
        ->and($context['images']['detached'])->toBe(0)
        ->and($context['images']['auto_attach_changed'])->toBeFalse();
});

test('POST /api/admin/hosts/{host}/images/preview-detach returns impacted instances, ignores deleted VMs and other hosts, leaves host_image unchanged', function (): void {
    $host1 = Host::factory()->create(['driver' => 'fake']);
    $host2 = Host::factory()->create(['driver' => 'fake']);

    $img1 = Image::factory()->create(['name' => 'ubuntu/22.04']);
    $img2 = Image::factory()->create(['name' => 'debian/12']);
    $img3 = Image::factory()->create(['name' => 'alpine/3.19']);

    DB::table('host_image')->where('host_id', $host1->id)->delete();
    DB::table('host_image')->insert([
        ['host_id' => $host1->id, 'image_id' => $img1->id],
        ['host_id' => $host1->id, 'image_id' => $img2->id],
        ['host_id' => $host1->id, 'image_id' => $img3->id],
    ]);

    $initialAttached = DB::table('host_image')->where('host_id', $host1->id)->pluck('image_id')->sort()->values()->all();

    // 2 active VMs on host1 using img1
    VirtualMachine::factory()->create([
        'host_id' => $host1->id,
        'user_id' => $this->customer->id,
        'os_image' => $img1->name,
        'status' => Running::class,
    ]);
    VirtualMachine::factory()->create([
        'host_id' => $host1->id,
        'user_id' => $this->customer->id,
        'os_image' => $img1->name,
        'status' => Stopped::class,
    ]);

    // 1 deleted VM on host1 using img1 (must be ignored)
    VirtualMachine::factory()->create([
        'host_id' => $host1->id,
        'user_id' => $this->customer->id,
        'os_image' => $img1->name,
        'status' => Deleted::class,
    ]);

    // 1 active VM on host2 using img1 (must be ignored)
    VirtualMachine::factory()->create([
        'host_id' => $host2->id,
        'user_id' => $this->customer->id,
        'os_image' => $img1->name,
        'status' => Running::class,
    ]);

    // 1 active VM on host1 using img2
    VirtualMachine::factory()->create([
        'host_id' => $host1->id,
        'user_id' => $this->customer->id,
        'os_image' => $img2->name,
        'status' => Running::class,
    ]);

    // Preview detaching img1 and img2 (mode: selected with only img3)
    $response = $this->actingAs($this->admin)->postJson("/api/v1/admin/hosts/{$host1->id}/images/preview-detach", [
        'images' => [
            'mode' => 'selected',
            'ids' => [$img3->id],
        ],
    ]);

    $response->assertStatus(200);
    $data = $response->json();

    expect($data['detached'])->toBe(2)
        ->and($data['total_instances'])->toBe(3)
        ->and($data['images'])->toHaveCount(2);

    $img1Data = collect($data['images'])->firstWhere('id', $img1->id);
    expect($img1Data)->not->toBeNull()
        ->and($img1Data['name'])->toBe($img1->name)
        ->and($img1Data['instance_count'])->toBe(2);

    $img2Data = collect($data['images'])->firstWhere('id', $img2->id);
    expect($img2Data)->not->toBeNull()
        ->and($img2Data['name'])->toBe($img2->name)
        ->and($img2Data['instance_count'])->toBe(1);

    // Verify host_image is completely unchanged
    $currentAttached = DB::table('host_image')->where('host_id', $host1->id)->pluck('image_id')->sort()->values()->all();
    expect($currentAttached)->toBe($initialAttached);
});

test('customer receives 403 on preview-detach and put host endpoints', function (): void {
    $host = Host::factory()->create(['driver' => 'fake']);

    $previewRes = $this->actingAs($this->customer)->postJson("/api/v1/admin/hosts/{$host->id}/images/preview-detach", [
        'images' => [
            'mode' => 'all',
        ],
    ]);
    $previewRes->assertForbidden();

    $updateRes = $this->actingAs($this->customer)->putJson("/api/v1/admin/hosts/{$host->id}", [
        'name' => 'Forbidden Host Name',
        'endpoint_url' => $host->endpoint_url,
    ]);
    $updateRes->assertForbidden();
});

test('connect-token and add-certificate endpoints validate auto_attach_images and image selection rules', function (): void {
    // 1. connect-token rejects invalid auto_attach_images
    $res1 = $this->actingAs($this->admin)->postJson('/api/v1/admin/hosts/connect-token', [
        'name' => 'Token Node 1',
        'token' => 'dummy-token',
        'auto_attach_images' => 'not-a-boolean',
    ]);
    $res1->assertStatus(422)->assertJsonValidationErrors(['auto_attach_images']);

    // 2. connect-token rejects empty ids in selected mode
    $res2 = $this->actingAs($this->admin)->postJson('/api/v1/admin/hosts/connect-token', [
        'name' => 'Token Node 2',
        'token' => 'dummy-token',
        'images' => [
            'mode' => 'selected',
            'ids' => [],
        ],
    ]);
    $res2->assertStatus(422)->assertJsonValidationErrors(['images.ids']);

    // 3. connect-token rejects non-existent image ID
    $res3 = $this->actingAs($this->admin)->postJson('/api/v1/admin/hosts/connect-token', [
        'name' => 'Token Node 3',
        'token' => 'dummy-token',
        'images' => [
            'mode' => 'selected',
            'ids' => [999999],
        ],
    ]);
    $res3->assertStatus(422)->assertJsonValidationErrors(['images.ids']);

    // 4. connect-token rejects unknown mode
    $res4 = $this->actingAs($this->admin)->postJson('/api/v1/admin/hosts/connect-token', [
        'name' => 'Token Node 4',
        'token' => 'dummy-token',
        'images' => [
            'mode' => 'invalid_mode',
        ],
    ]);
    $res4->assertStatus(422)->assertJsonValidationErrors(['images.mode']);

    // 5. add-certificate rejects invalid auto_attach_images
    $res5 = $this->actingAs($this->admin)->postJson('/api/v1/admin/hosts/add-certificate', [
        'name' => 'Cert Node 1',
        'address' => '10.0.0.5:8443',
        'expected_fingerprint' => 'abc',
        'auto_attach_images' => 'invalid',
    ]);
    $res5->assertStatus(422)->assertJsonValidationErrors(['auto_attach_images']);

    // 6. add-certificate rejects unknown filter key
    $res6 = $this->actingAs($this->admin)->postJson('/api/v1/admin/hosts/add-certificate', [
        'name' => 'Cert Node 2',
        'address' => '10.0.0.5:8443',
        'expected_fingerprint' => 'abc',
        'images' => [
            'mode' => 'filter',
            'filter' => ['unknown_key' => 'val'],
        ],
    ]);
    $res6->assertStatus(422)->assertJsonValidationErrors(['images.filter']);

    // 7. add-certificate rejects non-existent detach_ids
    $res7 = $this->actingAs($this->admin)->postJson('/api/v1/admin/hosts/add-certificate', [
        'name' => 'Cert Node 3',
        'address' => '10.0.0.5:8443',
        'expected_fingerprint' => 'abc',
        'images' => [
            'mode' => 'delta',
            'detach_ids' => [999999],
        ],
    ]);
    $res7->assertStatus(422)->assertJsonValidationErrors(['images.detach_ids']);
});
