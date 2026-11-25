<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Metrics\VmMetricsConfigService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Put;

/**
 * Controller handling API authentication and user profile access.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly ?UserService $userService = null,
    ) {}

    /**
     * Register a new user account.
     */
    #[Post('register', name: 'auth.register', middleware: ['throttle:auth-register'])]
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->toDTO());

        return response()->json([
            'message' => $result['message'],
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], 201);
    }

    /**
     * Authenticate existing user and issue API access token.
     */
    #[Post('login', name: 'auth.login', middleware: ['throttle:auth-login'])]
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->toDTO());

        return response()->json([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ]);
    }

    /**
     * Get current authenticated user details, roles, and quota summary.
     */
    #[Get('me', name: 'auth.me', middleware: ['auth:sanctum'])]
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load(['quota', 'roles', 'quotaGroup']);

        return response()->json([
            'user' => new UserResource($user),
            'roles' => $user->getRoleNames(),
        ]);
    }

    /**
     * Update current authenticated user's profile and optionally change password.
     */
    #[Put('me', name: 'auth.update_profile', middleware: ['auth:sanctum', 'active'])]
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updatedUser = $this->authService->updateProfile($user, $request->toDTO());

        return response()->json([
            'message' => __('messages.auth.profile_updated'),
            'user' => new UserResource($updatedUser),
        ]);
    }

    /**
     * Return public platform configuration (e.g. registration status and supported virtualization modes).
     */
    #[Get('settings/public', name: 'settings.public')]
    public function publicSettings(): JsonResponse
    {
        $metricsConfig = app(VmMetricsConfigService::class);

        return response()->json([
            'registration_enabled' => SystemSetting::isRegistrationEnabled(),
            'allow_virtual_machines' => SystemSetting::isVmEnabled(),
            'allow_containers' => SystemSetting::isContainerEnabled(),
            'metrics_enabled' => $metricsConfig->isEnabled(),
            'metrics_interval_seconds' => $metricsConfig->intervalSeconds(),
            'metrics_available_ranges' => $metricsConfig->availableRanges(),
        ]);
    }

    /**
     * Invalidate current access token.
     */
    #[Post('logout', name: 'auth.logout', middleware: ['auth:sanctum'])]
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authService->logout($user);

        return response()->json([
            'message' => __('messages.auth.logged_out'),
        ]);
    }

    /**
     * Delete current authenticated user's account.
     */
    #[Delete('me', name: 'auth.delete_account', middleware: ['auth:sanctum', 'active'])]
    public function deleteAccount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $service = $this->userService ?? app(UserService::class);
        $service->delete($user, $user);

        return response()->json([
            'message' => 'Account deletion initiated.',
        ], 202);
    }
}
