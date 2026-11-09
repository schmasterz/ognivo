<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AuditLog;
use Tests\TestCase;

final class AuditIpVarbinaryTest extends TestCase
{
    public function test_ipv4_and_ipv6_round_trip_through_inet_pton_and_ntop(): void
    {
        $ipv4 = '192.0.2.1';
        $binaryV4 = inet_pton($ipv4);
        $this->assertNotFalse($binaryV4);
        $this->assertSame(4, strlen($binaryV4));
        $this->assertSame($ipv4, inet_ntop($binaryV4));

        $ipv6 = '2001:db8:85a3::8a2e:370:7334';
        $binaryV6 = inet_pton($ipv6);
        $this->assertNotFalse($binaryV6);
        $this->assertSame(16, strlen($binaryV6));
        $this->assertSame($ipv6, inet_ntop($binaryV6));
    }

    public function test_audit_log_model_handles_ip_accessor_and_mutator_for_ipv4_and_ipv6(): void
    {
        $log = new AuditLog;

        // IPv4
        $log->ip = '198.51.100.42';
        $this->assertSame('198.51.100.42', $log->ip);
        $this->assertSame(inet_pton('198.51.100.42'), $log->getAttributes()['ip']);

        // IPv6
        $log->ip = '2001:db8::1';
        $this->assertSame('2001:db8::1', $log->ip);
        $this->assertSame(inet_pton('2001:db8::1'), $log->getAttributes()['ip']);

        // Null
        $log->ip = null;
        $this->assertNull($log->ip);
        $this->assertNull($log->getAttributes()['ip']);
    }
}
