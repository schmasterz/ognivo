<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\DTO\Quota\CreateQuotaGroupDTO;
use App\DTO\Quota\DefaultQuotaDTO;
use App\DTO\Quota\UpdateQuotaGroupDTO;
use App\Models\SystemSetting;
use App\Services\QuotaGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditSettingsQuotaCoverageTest extends TestCase
{
    use AuditAssertionTrait;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AuditContext::class)->setRequestId('req-settingsquota-test-3333');
    }

    public function test_settings_updated_audited_with_masked_smtp_password(): void
    {
        // 1. Array settings with smtp_password
        $mailSetting = SystemSetting::create([
            'key' => 'mail',
            'value' => [
                'host' => 'smtp.old.com',
                'port' => 587,
                'smtp_password' => 'super-secret-smtp-password',
            ],
        ]);

        $mailSetting->value = [
            'host' => 'smtp.new.com',
            'port' => 587,
            'smtp_password' => 'new-super-secret-password-must-not-appear',
        ];
        $mailSetting->save();

        $mailRow = $this->assertAuditRow(
            action: 'settings.updated',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-settingsquota-test-3333',
        );

        $mailCtx = json_decode((string) $mailRow->context, true);
        $this->assertSame('smtp.old.com', $mailCtx['diff']['host']['old']);
        $this->assertSame('smtp.new.com', $mailCtx['diff']['host']['new']);
        $this->assertArrayNotHasKey('port', $mailCtx['diff']); // port unchanged!
        $this->assertSame(['changed' => true], $mailCtx['diff']['smtp_password']);
        $this->assertStringNotContainsString('new-super-secret-password', (string) $mailRow->context);

        // 2. Direct key setting
        $siteSetting = SystemSetting::create([
            'key' => 'app_name',
            'value' => 'Old Ognivo',
        ]);
        $siteSetting->value = 'New Cloud Manager';
        $siteSetting->save();

        $siteRow = $this->assertAuditRow(
            action: 'settings.updated',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-settingsquota-test-3333',
        );
        $siteCtx = json_decode((string) $siteRow->context, true);
        $this->assertSame('Old Ognivo', $siteCtx['diff']['app_name']['old']);
        $this->assertSame('New Cloud Manager', $siteCtx['diff']['app_name']['new']);
    }

    public function test_quota_group_lifecycle_and_default_updated_audited(): void
    {
        $service = app(QuotaGroupService::class);

        // 1. quota.group.created
        $group = $service->create(new CreateQuotaGroupDTO(
            name: 'Gold Tier '.uniqid(),
            maxVms: 10,
            maxCpuCores: 20,
            maxRamMb: 20480,
            maxStorageGb: 300,
            maxIps: 5,
            description: 'Gold level service',
            isDefault: false,
            maxSnapshots: 5,
        ));

        $createdRow = $this->assertAuditRow(
            action: 'quota.group.created',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-settingsquota-test-3333',
        );
        $createdCtx = json_decode((string) $createdRow->context, true);
        $this->assertSame($group->name, $createdCtx['name']);
        $this->assertSame(10, $createdCtx['max_vms']);

        // 2. quota.group.updated (via Auditable)
        $service->update($group, new UpdateQuotaGroupDTO(
            name: $group->name,
            maxVms: 15,
            maxCpuCores: 30,
            maxRamMb: 30720,
            maxStorageGb: 400,
            maxIps: 8,
            description: 'Updated Gold level service',
            isDefault: false,
            maxSnapshots: 8,
        ));

        $updatedRow = $this->assertAuditRow(
            action: 'quota.group.updated',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-settingsquota-test-3333',
        );
        $updatedCtx = json_decode((string) $updatedRow->context, true);
        $this->assertSame(10, $updatedCtx['diff']['max_vms']['old']);
        $this->assertSame(15, $updatedCtx['diff']['max_vms']['new']);
        $this->assertSame('Gold level service', $updatedCtx['diff']['description']['old']);
        $this->assertSame('Updated Gold level service', $updatedCtx['diff']['description']['new']);

        // 3. quota.default.updated
        $service->updateDefaultQuota(new DefaultQuotaDTO(
            maxVms: 4,
            maxCpuCores: 8,
            maxRamMb: 8192,
            maxStorageGb: 80,
            maxIps: 2,
            maxSnapshots: 2,
        ));

        $defaultRow = $this->assertAuditRow(
            action: 'quota.default.updated',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-settingsquota-test-3333',
        );
        $defaultCtx = json_decode((string) $defaultRow->context, true);
        $this->assertSame(4, $defaultCtx['default_quota']['max_vms']);
        $this->assertSame(8, $defaultCtx['default_quota']['max_cpu_cores']);
    }
}
