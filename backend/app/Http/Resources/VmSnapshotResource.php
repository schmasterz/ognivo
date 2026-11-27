<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VmSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming VmSnapshot entity without exposing internal hypervisor details.
 *
 * @property-read VmSnapshot $resource
 *
 * @mixin VmSnapshot
 */
final class VmSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $snapshot = $this->resource;

        return [
            'id' => $snapshot->id,
            'virtual_machine_id' => $this->whenLoaded('virtualMachine', fn () => $snapshot->virtual_machine_id),
            'virtual_machine' => $this->whenLoaded('virtualMachine', fn () => [
                'id' => $snapshot->virtualMachine->id,
                'name' => $snapshot->virtualMachine->name,
                'incus_project' => $snapshot->virtualMachine->incus_project,
                'status' => $snapshot->virtualMachine->status,
                'host' => $snapshot->virtualMachine->host !== null ? [
                    'id' => $snapshot->virtualMachine->host->id,
                    'name' => $snapshot->virtualMachine->host->name,
                    'status' => $snapshot->virtualMachine->host->status->getValue(),
                ] : null,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $snapshot->user->id,
                'name' => $snapshot->user->name,
                'email' => $snapshot->user->email,
            ]),
            'name' => $snapshot->name,
            'source' => $snapshot->source,
            'status' => $snapshot->status,
            'size_bytes' => $snapshot->size_bytes,
            'error' => self::sanitizeError($snapshot->error),
            'created_at' => $snapshot->created_at?->toISOString(),
        ];
    }

    /**
     * Ensure internal hypervisor endpoint URLs or PEM certificates are never leaked in error strings.
     */
    private static function sanitizeError(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return $error;
        }

        $sanitized = preg_replace('/https?:\/\/[^\s"\'<>]+/i', '[redacted]', $error) ?? $error;
        $sanitized = preg_replace('/-----BEGIN [^-]+-----[\s\S]*?-----END [^-]+-----/', '[redacted]', $sanitized) ?? $sanitized;

        return $sanitized;
    }
}
