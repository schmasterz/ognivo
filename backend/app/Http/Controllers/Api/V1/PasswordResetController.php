<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Spatie\RouteAttributes\Attributes\Post;

/**
 * Controller handling the "forgot password" flow: emailing reset links and resetting passwords.
 */
final class PasswordResetController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * Send a password reset link to the given email address.
     *
     * Always returns a generic success message to avoid leaking which emails are registered.
     */
    #[Post('forgot-password', name: 'auth.forgot_password', middleware: ['throttle:6,1'])]
    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        /** @var string $email */
        $email = $request->validated('email');

        $status = Password::sendResetLink(['email' => $email]);

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => __('messages.auth.reset_throttled'),
            ], 429);
        }

        return response()->json([
            'message' => __('messages.auth.reset_link_sent'),
        ]);
    }

    /**
     * Reset the user's password using a valid reset token.
     */
    #[Post('reset-password', name: 'auth.reset_password', middleware: ['throttle:6,1'])]
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        /** @var array{token: string, email: string, password: string} $data */
        $data = $request->only(['email', 'password', 'password_confirmation', 'token']);

        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $this->authService->resetPassword($user, $password);

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __('messages.auth.reset_invalid_token'),
                'errors' => ['token' => [__('messages.auth.reset_invalid_token')]],
            ], 422);
        }

        return response()->json([
            'message' => __('messages.auth.reset_success'),
        ]);
    }
}
