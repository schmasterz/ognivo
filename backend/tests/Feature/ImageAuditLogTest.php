<?php

declare(strict_types=1);

use App\Audit\AuditAction;
use App\Audit\AuditOutcome;
use App\Models\AuditLog;
use App\Models\Host;
use App\Models\Image;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole($adminRole);

    Http::preventStrayRequests();
});

test('creating an image records image.created audit log entry', function (): void {
    $host = Host::factory()->create();

    $response = $this->actingAs($this->admin)->postJson('/api/admin/images', [
        'name' => 'debian/12-audit-test',
        'alias' => 'Debian 12 Audit Test',
        'source' => 'https://images.linuxcontainers.org',
        'protocol' => 'simplestreams',
        'os_type' => 'linux',
        'enabled' => true,
        'host_ids' => [$host->id],
        'skip_remote_validation' => true,
    ]);

    $response->assertStatus(201);

    $log = AuditLog::where('action', AuditAction::ImageCreated->value)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe(AuditAction::ImageCreated->value)
        ->and($log->outcome)->toBe(AuditOutcome::Success->value)
        ->and($log->visibility)->toBe('admin')
        ->and($log->actor_id)->toBe($this->admin->id)
        ->and($log->context['name'])->toBe('debian/12-audit-test')
        ->and($log->context['alias'])->toBe('Debian 12 Audit Test')
        ->and($log->context['attached_hosts_count'])->toBe(1);
});

test('updating an image records image.updated audit log entry with diff', function (): void {
    $image = Image::factory()->create([
        'name' => 'ubuntu/24.04-audit',
        'alias' => 'Ubuntu 24.04 Original',
        'enabled' => true,
    ]);

    $response = $this->actingAs($this->admin)->putJson("/api/admin/images/{$image->id}", [
        'alias' => 'Ubuntu 24.04 Updated',
        'enabled' => false,
        'skip_remote_validation' => true,
    ]);

    $response->assertStatus(200);

    $log = AuditLog::where('action', AuditAction::ImageUpdated->value)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe(AuditAction::ImageUpdated->value)
        ->and($log->outcome)->toBe(AuditOutcome::Success->value)
        ->and($log->visibility)->toBe('admin')
        ->and($log->actor_id)->toBe($this->admin->id)
        ->and($log->context['changes']['alias']['new'])->toBe('Ubuntu 24.04 Updated')
        ->and($log->context['changes']['enabled']['new'])->toBe(false);
});

test('deleting an image records image.deleted audit log entry', function (): void {
    $image = Image::factory()->create([
        'name' => 'alpine/delete-audit',
        'alias' => 'Alpine To Delete',
    ]);
    $host = Host::factory()->create();
    $image->hosts()->sync([$host->id]);

    $response = $this->actingAs($this->admin)->deleteJson("/api/admin/images/{$image->id}");
    $response->assertStatus(200);

    $log = AuditLog::where('action', AuditAction::ImageDeleted->value)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe(AuditAction::ImageDeleted->value)
        ->and($log->outcome)->toBe(AuditOutcome::Success->value)
        ->and($log->visibility)->toBe('admin')
        ->and($log->actor_id)->toBe($this->admin->id)
        ->and($log->context['name'])->toBe('alpine/delete-audit')
        ->and($log->context['unlinked_hosts_count'])->toBe(1);
});
