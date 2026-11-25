<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\User;
use App\Services\SystemOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Spatie\RouteAttributes\Attributes\Put;

/**
 * Controller managing cluster-wide infrastructure telemetry, resource usage overviews, and system settings.
 */
#[Prefix('admin')]
#[Middleware(['auth:sanctum', 'active', 'admin'])]
final class SystemOverviewController extends Controller
{
    public function __construct(
        private readonly SystemOverviewService $overviewService,
    ) {}

    /**
     * Get global cluster resource statistics across all tenants with optional filters.
     */
    #[Get('stats', name: 'admin.stats')]
    public function stats(Request $request): JsonResponse
    {
        /** @var array{user_id?: int|null, host_id?: int|null, status?: string|null, search?: string|null} $filters */
        $filters = [
            'user_id' => $request->filled('user_id') ? $request->integer('user_id') : null,
            'host_id' => $request->filled('host_id') ? $request->integer('host_id') : null,
            'status' => $request->filled('status') ? $request->string('status')->value() : null,
            'search' => $request->filled('search') ? $request->string('search')->value() : null,
        ];

        return response()->json([
            'overview' => $this->overviewService->getStats($filters),
        ]);
    }

    /**
     * Retrieve system-wide platform settings.
     */
    #[Get('settings', name: 'admin.settings.get')]
    public function getSettings(Request $request): JsonResponse
    {
        return response()->json([
            'settings' => $this->settingsPayload(),
        ]);
    }

    /**
     * Update system-wide platform settings.
     */
    #[Put('settings', name: 'admin.settings.update')]
    public function updateSettings(UpdateSettingsRequest $request): JsonResponse
    {
        $dto = $request->toDTO();
        if ($dto->registrationEnabled !== null) {
            $this->overviewService->setRegistrationEnabled($dto->registrationEnabled);
        }
        if ($dto->allowVirtualMachines !== null) {
            $this->overviewService->setVmEnabled($dto->allowVirtualMachines);
        }
        if ($dto->allowContainers !== null) {
            $this->overviewService->setContainerEnabled($dto->allowContainers);
        }
        if ($dto->frontendUrl !== null) {
            $this->overviewService->setFrontendUrl($dto->frontendUrl);
        }
        if ($dto->smtp !== null) {
            $this->overviewService->setSmtpSettings($dto->smtp);
        }
        if ($dto->metricsEnabled !== null) {
            $this->overviewService->setMetricsEnabled($dto->metricsEnabled);
        }
        if ($dto->metricsIntervalSeconds !== null) {
            $this->overviewService->setMetricsIntervalSeconds($dto->metricsIntervalSeconds);
        }
        if (
            $dto->metricsRetentionRawHours !== null
            || $dto->metricsRetention5mDays !== null
            || $dto->metricsRetention1hDays !== null
        ) {
            $this->overviewService->setMetricsRetention(
                $dto->metricsRetentionRawHours,
                $dto->metricsRetention5mDays,
                $dto->metricsRetention1hDays,
            );
        }

        return response()->json([
            'message' => __('messages.admin.settings_updated'),
            'settings' => $this->settingsPayload(),
        ]);
    }

    /**
     * Build the standardized admin settings response payload.
     *
     * @return array{
     *     registration_enabled: bool,
     *     allow_virtual_machines: bool,
     *     allow_containers: bool,
     *     frontend_url: string,
     *     smtp: array{
     *         enabled: bool,
     *         host: string|null,
     *         port: int,
     *         encryption: string,
     *         username: string|null,
     *         has_password: bool,
     *         from_address: string|null,
     *         from_name: string|null
     *     },
     *     metrics_enabled: bool,
     *     metrics_interval_seconds: int,
     *     metrics_retention: array{raw_hours: int, rollup_5m_days: int, rollup_1h_days: int},
     *     metrics_available_ranges: list<string>
     * }
     */
    private function settingsPayload(): array
    {
        return [
            'registration_enabled' => $this->overviewService->isRegistrationEnabled(),
            'allow_virtual_machines' => $this->overviewService->isVmEnabled(),
            'allow_containers' => $this->overviewService->isContainerEnabled(),
            'frontend_url' => $this->overviewService->getFrontendUrl(),
            'smtp' => $this->overviewService->getSmtpSettings(),
            'metrics_enabled' => $this->overviewService->isMetricsEnabled(),
            'metrics_interval_seconds' => $this->overviewService->getMetricsIntervalSeconds(),
            'metrics_retention' => $this->overviewService->getMetricsRetention(),
            'metrics_available_ranges' => $this->overviewService->getMetricsAvailableRanges(),
        ];
    }

    /**
     * Send a verification email using the configured SMTP settings.
     */
    #[Post('settings/test-smtp', name: 'admin.settings.test_smtp')]
    public function testSmtp(Request $request): JsonResponse
    {
        $request->validate([
            'recipient_email' => ['nullable', 'email', 'max:255'],
        ]);

        /** @var User $admin */
        $admin = $request->user();
        /** @var string|null $recipientInput */
        $recipientInput = $request->input('recipient_email');
        $recipient = ! empty($recipientInput) ? $recipientInput : $admin->email;

        try {
            $this->overviewService->sendTestEmail($recipient);

            return response()->json([
                'success' => true,
                'message' => __('messages.admin.smtp_test_success', ['email' => $recipient]),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('messages.admin.smtp_test_failed', ['error' => $e->getMessage()]),
            ], 422);
        }
    }
}
