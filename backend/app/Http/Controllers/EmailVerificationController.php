<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\Cast;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;

/**
 * Controller handling user email verification and token validation.
 */
final class EmailVerificationController extends Controller
{
    /**
     * Verify email address using cryptographic HMAC signature payload (POST from frontend).
     */
    #[Post('email/verify', name: 'auth.verify_email', middleware: ['throttle:10,1'])]
    public function verify(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'hash' => ['required', 'string'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ]);

        $id = Cast::int($validated['id'] ?? 0);
        $hash = Cast::string($validated['hash'] ?? '');
        $expires = Cast::int($validated['expires'] ?? 0);
        $signature = Cast::string($validated['signature'] ?? '');

        if ($expires < time()) {
            return response()->json([
                'message' => __('messages.auth.verification_link_invalid'),
                'errors' => ['signature' => [__('messages.auth.verification_link_invalid')]],
            ], 422);
        }

        $appKey = Cast::string(config('app.key'));
        $expectedSignature = hash_hmac('sha256', "verify:{$id}:{$hash}:{$expires}", $appKey);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'message' => __('messages.auth.verification_link_invalid'),
                'errors' => ['signature' => [__('messages.auth.verification_link_invalid')]],
            ], 422);
        }

        /** @var User|null $user */
        $user = User::find($id);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json([
                'message' => __('messages.auth.verification_link_invalid'),
                'errors' => ['signature' => [__('messages.auth.verification_link_invalid')]],
            ], 422);
        }

        if ($user->hasVerifiedEmail()) {
            if ($user->status === 'pending') {
                $user->status = 'active';
                $user->save();
            }

            return response()->json([
                'message' => __('messages.auth.email_already_verified'),
                'already_verified' => true,
            ]);
        }

        $user->markEmailAsVerified();
        $user->status = 'active';
        $user->save();

        event(new Verified($user));

        return response()->json([
            'message' => __('messages.auth.email_verified_success'),
            'already_verified' => false,
        ]);
    }

    /**
     * Fallback verification via direct GET link in email or external client.
     */
    #[Get('email/verify/{id}/{hash}', name: 'verification.verify', middleware: ['throttle:10,1'])]
    public function verifyViaGet(Request $request, int $id, string $hash): JsonResponse|RedirectResponse
    {
        $expires = $request->integer('expires');
        $signature = $request->string('signature')->value();
        $frontendUrl = SystemSetting::getFrontendUrl();

        if ($expires < time() || $signature === '') {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => __('messages.auth.verification_link_invalid'),
                ], 422);
            }

            return redirect()->to($frontendUrl.'/verify-email?'.http_build_query([
                'error' => 'invalid_or_expired',
            ]));
        }

        $appKey = Cast::string(config('app.key'));
        $expectedSignature = hash_hmac('sha256', "verify:{$id}:{$hash}:{$expires}", $appKey);

        if (! hash_equals($expectedSignature, $signature)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => __('messages.auth.verification_link_invalid'),
                ], 422);
            }

            return redirect()->to($frontendUrl.'/verify-email?'.http_build_query([
                'error' => 'invalid_signature',
            ]));
        }

        /** @var User|null $user */
        $user = User::find($id);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => __('messages.auth.verification_link_invalid'),
                ], 422);
            }

            return redirect()->to($frontendUrl.'/verify-email?'.http_build_query([
                'error' => 'user_not_found',
            ]));
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            $user->status = 'active';
            $user->save();

            event(new Verified($user));
        } elseif ($user->status === 'pending') {
            $user->status = 'active';
            $user->save();
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => __('messages.auth.email_verified_success'),
            ]);
        }

        return redirect()->to($frontendUrl.'/verify-email?'.http_build_query([
            'id' => $id,
            'hash' => $hash,
            'verified' => 1,
        ]));
    }

    /**
     * Resend verification email to a pending tenant user.
     */
    #[Post('email/resend-verification', name: 'auth.resend_verification', middleware: ['throttle:6,1'])]
    public function resend(Request $request): JsonResponse
    {
        /** @var array{email: string} $validated */
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        /** @var User|null $user */
        $user = User::where('email', $validated['email'])->first();

        if ($user !== null && $user->status === 'pending' && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => __('messages.auth.resend_verification_sent'),
        ]);
    }
}
