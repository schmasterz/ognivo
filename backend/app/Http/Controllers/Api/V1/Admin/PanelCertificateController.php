<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Services\PanelCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;

final class PanelCertificateController extends Controller
{
    public function __construct(
        private readonly PanelCertificateService $certService,
    ) {}

    /**
     * Get active panel mTLS certificate metadata (never private key).
     */
    #[Get('admin/incus/certificate', name: 'admin.incus.certificate.show', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function show(): JsonResponse
    {
        $active = $this->certService->getActiveCertificate();
        if ($active === null) {
            $active = $this->certService->generate();
        }
        $retiring = $this->certService->getRetiringCertificate();
        $pendingHostsCount = Host::where('needs_retrust', true)->count();

        return response()->json([
            'certificate' => [
                'id' => $active->id,
                'fingerprint' => $active->fingerprint,
                'not_before' => $active->not_before->toISOString(),
                'not_after' => $active->not_after->toISOString(),
                'status' => $active->status->getValue(),
                'is_expired' => $active->isExpired(),
                'is_expiring_soon' => $active->isExpiringSoon(30),
                'days_until_expiration' => now()->diffInDays($active->not_after, false),
            ],
            'retiring_certificate' => $retiring !== null ? [
                'id' => $retiring->id,
                'fingerprint' => $retiring->fingerprint,
                'status' => $retiring->status->getValue(),
                'not_after' => $retiring->not_after->toISOString(),
            ] : null,
            'pending_retrust_hosts_count' => $pendingHostsCount,
        ]);
    }

    /**
     * Download the active public X.509 certificate as a .crt file.
     * Never returns or exposes the private key.
     */
    #[Get('admin/incus/certificate/download', name: 'admin.incus.certificate.download', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function download(): Response
    {
        $active = $this->certService->downloadCertificate();

        return response($active->certificate, 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="incus-panel.crt"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Rotate panel mTLS certificate.
     */
    #[Post('admin/incus/certificate/rotate', name: 'admin.incus.certificate.rotate', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function rotate(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:30', 'max:1825'],
        ]);

        $days = isset($validated['days']) && is_numeric($validated['days']) ? (int) $validated['days'] : 365;
        $newCert = $this->certService->generate($days);

        return response()->json([
            'message' => 'Panel certificate rotated successfully. Previous certificate marked as retiring.',
            'certificate' => [
                'id' => $newCert->id,
                'fingerprint' => $newCert->fingerprint,
                'not_before' => $newCert->not_before->toISOString(),
                'not_after' => $newCert->not_after->toISOString(),
                'status' => $newCert->status->getValue(),
            ],
            'pending_retrust_hosts_count' => Host::where('needs_retrust', true)->count(),
        ]);
    }

    /**
     * Revoke the retiring certificate once all hosts are confirmed re-trusted.
     */
    #[Post('admin/incus/certificate/revoke-old', name: 'admin.incus.certificate.revoke_old', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function revokeOld(): JsonResponse
    {
        $command = $this->certService->revokeOldCertificate();

        return response()->json([
            'message' => 'Old certificate revoked successfully.',
            'cleanup_instruction' => $command,
        ]);
    }
}
