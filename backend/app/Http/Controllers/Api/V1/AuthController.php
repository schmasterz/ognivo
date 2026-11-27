<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Metrics\VmMetricsConfigService;
use App\Services\UserService;
use App\States\User\Active;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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
     *
     * Starts a browser session automatically (via httpOnly session cookie) when the registered account
     * is immediately active, and returns no API token. Requires a valid X-XSRF-TOKEN header
     * obtained from GET /sanctum/csrf-cookie.
     */
    #[Post('register', name: 'auth.register', middleware: ['throttle:auth-register'])]
    public function register(RegisterRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            abort(403, 'This endpoint is available to the browser session only.');
        }

        $result = $this->authService->register($request->toDTO());

        if ($result['user']->status->equals(Active::class)) {
            $this->establishBrowserSession($request, $result['user']);
        }

        return response()->json([
            'message' => $result['message'],
            'user' => new UserResource($result['user']),
        ], 201);
    }

    /**
     * Authenticate existing user and establish a browser session.
     *
     * Starts a browser session, sets the httpOnly session cookie, and returns {user} with no API token.
     * Requires a valid X-XSRF-TOKEN header obtained from GET /sanctum/csrf-cookie.
     */
    #[Post('login', name: 'auth.login', middleware: ['throttle:auth-login'])]
    #[Response(status: 401, description: 'Invalid credentials.', type: 'array{message: string}')]
    #[Response(status: 419, description: 'CSRF token mismatch.', type: 'array{message: string}')]
    #[Response(status: 422, description: 'Validation error.')]
    #[Response(status: 429, description: 'Too many login attempts.')]
    public function login(LoginRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            abort(403, 'This endpoint is available to the browser session only.');
        }

        $user = $this->authService->login($request->toDTO());

        $this->establishBrowserSession($request, $user);

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Get current authenticated user details, roles, impersonation state, and session expiry.
     *
     * Requires an active browser session cookie and returns user, roles, impersonation, and session_expires_at.
     */
    #[Get('me', name: 'auth.me', middleware: ['auth:sanctum'])]
    #[Response(status: 401, description: 'Unauthenticated.')]
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load(['quota', 'roles', 'quotaGroup']);

        return response()->json([
            'user' => new UserResource($user),
            'roles' => $user->getRoleNames(),
            'impersonation' => $this->resolveImpersonationPayload($request),
            'session_expires_at' => $this->resolveSessionExpiresAt($request),
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
     * Invalidate current browser session.
     *
     * Requires an active browser session cookie and a valid X-XSRF-TOKEN header
     * obtained from GET /sanctum/csrf-cookie.
     */
    #[Post('logout', name: 'auth.logout', middleware: ['auth:sanctum'])]
    #[Response(status: 401, description: 'Unauthenticated.')]
    #[Response(status: 419, description: 'CSRF token mismatch.', type: 'array{message: string}')]
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authService->logout($user);

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

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

    /**
     * Log the user into the web guard and regenerate the browser session.
     */
    private function establishBrowserSession(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('login_at', Carbon::now()->getTimestamp());
    }

    /**
     * Resolve active impersonation metadata from the current browser session.
     *
     * @return array{active: bool, impersonator_id: int, impersonator_name: string, expires_at: string|null}|null
     */
    private function resolveImpersonationPayload(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $impersonatorId = app(AuditContext::class)->impersonatorIdFromRequest($request);
        if ($impersonatorId === null) {
            return null;
        }

        $rawExpiresAt = $request->session()->get('impersonation_expires_at');
        if (! is_int($rawExpiresAt) && ! (is_string($rawExpiresAt) && ctype_digit($rawExpiresAt))) {
            return null;
        }

        $rawName = User::query()->whereKey($impersonatorId)->value('name');
        $impersonatorName = is_string($rawName) && $rawName !== '' ? $rawName : 'Admin';

        return [
            'active' => true,
            'impersonator_id' => $impersonatorId,
            'impersonator_name' => $impersonatorName,
            'expires_at' => Carbon::createFromTimestamp((int) $rawExpiresAt)->toISOString(),
        ];
    }

    /**
     * Resolve absolute browser session expiration timestamp from session login time.
     */
    private function resolveSessionExpiresAt(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $loginAt = $request->session()->get('login_at');
        if (! is_numeric($loginAt)) {
            return null;
        }

        /** @var mixed $rawAbsoluteLifetime */
        $rawAbsoluteLifetime = config('session.absolute_lifetime', 1440);
        $absoluteMinutes = is_numeric($rawAbsoluteLifetime) ? (int) $rawAbsoluteLifetime : 1440;

        return Carbon::createFromTimestamp((int) $loginAt)
            ->addMinutes($absoluteMinutes)
            ->toISOString();
    }
}
