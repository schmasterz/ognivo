<?php

declare(strict_types=1);

use App\Models\IpPool;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Clean existing ip_pools where the gateway IP was persisted into excluded_ips.
     */
    public function up(): void
    {
        $pools = IpPool::query()
            ->whereNotNull('gateway')
            ->whereNotNull('excluded_ips')
            ->get();

        foreach ($pools as $pool) {
            $excluded = $pool->excluded_ips;
            if (! is_array($excluded) || $pool->gateway === null || $pool->gateway === '') {
                continue;
            }

            $cleaned = array_values(array_filter(
                $excluded,
                static fn (mixed $ip): bool => is_string($ip) && $ip !== $pool->gateway
            ));

            if ($cleaned !== $excluded) {
                $pool->excluded_ips = empty($cleaned) ? null : $cleaned;
                $pool->saveQuietly();
            }
        }
    }

    /**
     * Reverse the migrations.
     * No-op: gateway exclusions are dynamically handled in runtime.
     */
    public function down(): void
    {
        // Safe no-op
    }
};
