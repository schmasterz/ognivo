<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Contract for pluggable DNS management providers (Developer Preview).
 *
 * @experimental This extension contract is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
interface DnsProviderInterface
{
    /**
     * Create a new DNS record.
     *
     * @param  string  $zone  The DNS zone (e.g. "example.com").
     * @param  string  $name  Subdomain or record name (e.g. "host").
     * @param  string  $type  Record type (e.g. "A", "AAAA", "CNAME", "TXT").
     * @param  string  $content  Value / target.
     * @param  int  $ttl  Time to live in seconds (default 300).
     * @return bool True if record was created successfully.
     */
    public function createRecord(string $zone, string $name, string $type, string $content, int $ttl = 300): bool;

    /**
     * Delete an existing DNS record.
     *
     * @param  string  $zone  The DNS zone.
     * @param  string  $recordId  Unique provider record identifier.
     * @return bool True if record was deleted.
     */
    public function deleteRecord(string $zone, string $recordId): bool;

    /**
     * List DNS records in the specified zone.
     *
     * @param  string  $zone  The DNS zone.
     * @return list<array{id: string, name: string, type: string, content: string, ttl: int}>
     */
    public function listRecords(string $zone): array;
}
