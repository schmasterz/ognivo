<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\AuditRedactor;
use App\Models\User;
use Tests\TestCase;

final class AuditRedactorTest extends TestCase
{
    public function test_redactor_removes_secrets_recursively_case_insensitively(): void
    {
        $input = [
            'username' => 'alice',
            'Password' => 'secret123',
            'PASSWORD_CONFIRMATION' => 'secret123',
            'nested' => [
                'token' => 'jwt.token.here',
                'Trust_Token' => 'trust_val',
                'deep' => [
                    'private_key' => '---PRIVATE KEY---',
                    'server_certificate' => '---CERT---',
                    'SMTP_PASSWORD' => 'smtp_secret',
                    'secret' => 'top_secret',
                    'Authorization' => 'Bearer 123456',
                    'safe_key' => 'safe_value',
                ],
            ],
            'safe_number' => 42,
        ];

        $cleaned = AuditRedactor::clean($input);

        $this->assertSame('alice', $cleaned['username']);
        $this->assertSame(42, $cleaned['safe_number']);
        $this->assertArrayNotHasKey('Password', $cleaned);
        $this->assertArrayNotHasKey('PASSWORD_CONFIRMATION', $cleaned);
        $this->assertArrayNotHasKey('token', $cleaned['nested']);
        $this->assertArrayNotHasKey('Trust_Token', $cleaned['nested']);
        $this->assertArrayNotHasKey('private_key', $cleaned['nested']['deep']);
        $this->assertArrayNotHasKey('server_certificate', $cleaned['nested']['deep']);
        $this->assertArrayNotHasKey('SMTP_PASSWORD', $cleaned['nested']['deep']);
        $this->assertArrayNotHasKey('secret', $cleaned['nested']['deep']);
        $this->assertArrayNotHasKey('Authorization', $cleaned['nested']['deep']);
        $this->assertSame('safe_value', $cleaned['nested']['deep']['safe_key']);
    }

    public function test_redactor_strips_cloud_init_user_data_ticket_api_key_and_cookie(): void
    {
        $input = [
            'CLOUD_INIT' => '#cloud-config',
            'nested' => [
                'user-data' => '#cloud-config user data',
                'Ticket' => 'secret-vnc-ticket',
                'deep' => [
                    'API_KEY' => 'my-secret-key',
                    'Cookie' => 'session=xyz123',
                    'normal_key' => 'normal_value',
                ],
            ],
        ];

        $cleaned = AuditRedactor::clean($input);

        $this->assertArrayNotHasKey('CLOUD_INIT', $cleaned);
        $this->assertArrayNotHasKey('user-data', $cleaned['nested']);
        $this->assertArrayNotHasKey('Ticket', $cleaned['nested']);
        $this->assertArrayNotHasKey('API_KEY', $cleaned['nested']['deep']);
        $this->assertArrayNotHasKey('Cookie', $cleaned['nested']['deep']);
        $this->assertSame('normal_value', $cleaned['nested']['deep']['normal_key']);
    }

    public function test_redactor_truncates_strings_longer_than_500_chars(): void
    {
        $longString = str_repeat('a', 600);
        $data = [
            'normal' => 'short',
            'long' => $longString,
        ];

        $cleaned = AuditRedactor::clean($data);

        $this->assertSame('short', $cleaned['normal']);
        $this->assertSame(500, mb_strlen($cleaned['long']));
        $this->assertSame(str_repeat('a', 500), $cleaned['long']);
    }

    public function test_redactor_caps_serialized_context_at_8kb_and_drops_largest_first(): void
    {
        // Construct context exceeding 8 KB (8192 bytes)
        $small1 = ['key' => 'small1', 'val' => str_repeat('s', 100)];
        $medium = ['key' => 'medium', 'val' => str_repeat('m', 450)];
        $largeEntries = [];
        for ($i = 0; $i < 30; $i++) {
            $largeEntries["entry_{$i}"] = str_repeat('x', 490);
        }

        $input = [
            'small' => $small1,
            'medium' => $medium,
            'bulk' => $largeEntries,
        ];

        $this->assertGreaterThan(8192, strlen((string) json_encode($input)));

        $capped = AuditRedactor::capSize($input, 8192);

        $json = json_encode($capped);
        $this->assertNotFalse($json);
        $this->assertLessThanOrEqual(8192, strlen($json));
        $this->assertTrue($capped['_truncated']);
        // Small values should be preserved while large entries from bulk were dropped first
        $this->assertArrayHasKey('small', $capped);
    }

    public function test_redactor_diff_excludes_hidden_model_attributes(): void
    {
        $user1 = new User([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'old_hash',
            'remember_token' => 'old_token',
        ]);

        $user2 = new User([
            'name' => 'Alice Smith',
            'email' => 'alice@example.com',
            'password' => 'new_hash',
            'remember_token' => 'new_token',
        ]);

        $diff = AuditRedactor::diff($user1, $user2);

        $this->assertArrayHasKey('name', $diff);
        $this->assertSame('Alice', $diff['name']['old']);
        $this->assertSame('Alice Smith', $diff['name']['new']);

        // User's $hidden includes password, remember_token
        $this->assertArrayNotHasKey('password', $diff);
        $this->assertArrayNotHasKey('remember_token', $diff);
    }
}
