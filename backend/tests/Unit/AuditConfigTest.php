<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class AuditConfigTest extends TestCase
{
    public function test_audit_config_structure(): void
    {
        $config = config('audit');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('strict_grants', $config);
        $this->assertArrayHasKey('database', $config);
        $this->assertArrayHasKey('hmac', $config);
        $this->assertArrayHasKey('pseudonym_salt', $config);
        $this->assertArrayHasKey('retention_days', $config);
        $this->assertArrayHasKey('seal_delay_seconds', $config);
        $this->assertArrayHasKey('attempted_email_days', $config);
        $this->assertArrayHasKey('log_stderr', $config);

        $this->assertSame(400, $config['retention_days']);
        $this->assertSame(120, $config['seal_delay_seconds']);
        $this->assertSame(30, $config['attempted_email_days']);
        $this->assertIsArray($config['hmac']['keys']);
    }

    public function test_hmac_keys_map_includes_current_key(): void
    {
        config([
            'audit.hmac.current_key_id' => 'v1',
            'audit.hmac.current_key' => 'secret_v1_key',
            'audit.hmac.keys' => [
                'v1' => 'secret_v1_key',
            ],
        ]);

        $this->assertSame('secret_v1_key', config('audit.hmac.keys.v1'));
        $this->assertSame('v1', config('audit.hmac.current_key_id'));
    }

    public function test_hmac_keys_map_supports_rotation_with_previous_keys(): void
    {
        // When rotated to v2, both v1 and v2 are available in the map
        config([
            'audit.hmac.current_key_id' => 'v2',
            'audit.hmac.current_key' => 'new_secret_v2_key',
            'audit.hmac.keys' => [
                'v1' => 'old_secret_v1_key',
                'v2' => 'new_secret_v2_key',
            ],
        ]);

        $keys = config('audit.hmac.keys');
        $this->assertArrayHasKey('v1', $keys);
        $this->assertArrayHasKey('v2', $keys);
        $this->assertSame('old_secret_v1_key', $keys['v1']);
        $this->assertSame('new_secret_v2_key', $keys['v2']);
        $this->assertSame('v2', config('audit.hmac.current_key_id'));
    }
}
