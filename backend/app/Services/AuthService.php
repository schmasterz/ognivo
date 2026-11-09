<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Auth\LoginDTO;
use App\DTO\Auth\RegisterDTO;
use App\DTO\Auth\UpdateProfileDTO;
use App\Models\QuotaGroup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserQuota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;

/**
 * Service managing user authentication, registration onboarding, and credential lifecycle.
 */
final class AuthService
{
    public function __construct(
        private readonly ?AuditLogger $auditLogger = null,
    ) {}

    private function logger(): AuditLogger
    {
        return $this->auditLogger ?? app(AuditLogger::class);
    }

    /**
     * Register a new tenant user and provision default quotas and permissions.
     *
     * @return array{user: User, token: string|null, message: string}
     */
    public function register(RegisterDTO $dto): array
    {
        if (! SystemSetting::isRegistrationEnabled()) {
            $msg = __('messages.auth.registration_disabled');
            abort(403, is_string($msg) ? $msg : 'Public registration is disabled.');
        }

        return DB::transaction(function () use ($dto): array {
            /** @var User $user */
            $user = User::create([
                'name' => $dto->name,
                'email' => $dto->email,
                'password' => Hash::make($dto->password),
                'status' => 'pending',
            ]);

            /** @var Role|null $customerRole */
            $customerRole = Role::where('name', 'customer')->first();
            if ($customerRole !== null) {
                $user->assignRole($customerRole);
            }

            $defaultGroup = QuotaGroup::getDefaultGroup();
            if ($defaultGroup !== null) {
                $user->quota_group_id = $defaultGroup->id;
                $user->save();
            }

            UserQuota::create(array_merge(
                [
                    'user_id' => $user->id,
                    'quota_group_id' => $defaultGroup?->id,
                ],
                SystemSetting::getDefaultQuota()
            ));

            $this->logger()->record(new AuditEntry(
                action: AuditAction::AuthRegister,
                outcome: AuditOutcome::Success,
                subject: $user,
                tenantUserId: $user->id,
                visibility: 'tenant',
                pii: [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ));

            // Queue asynchronous email verification notification
            $user->sendEmailVerificationNotification();

            return [
                'user' => $user,
                'token' => null,
                'message' => (string) __('messages.auth.verification_email_sent'),
            ];
        });
    }

    /**
     * Authenticate an existing user and generate an API access token.
     *
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function login(LoginDTO $dto): array
    {
        $attemptedEmail = strtolower(trim($dto->email));

        /** @var User|null $user */
        $user = User::where('email', $attemptedEmail)->first();

        if ($user === null || ! Hash::check($dto->password, $user->password)) {
            $this->logger()->recordIndependent(new AuditEntry(
                action: AuditAction::AuthLoginFailed,
                outcome: AuditOutcome::Failed,
                subject: $user,
                tenantUserId: $user?->id,
                visibility: $user !== null ? 'tenant' : 'admin',
                pii: [
                    'attempted_email' => $attemptedEmail,
                ],
                actorId: null,
            ));

            throw ValidationException::withMessages([
                'email' => [__('messages.auth.invalid_credentials')],
            ]);
        }

        if ($user->status !== 'active') {
            $this->logger()->recordIndependent(new AuditEntry(
                action: AuditAction::AuthLoginFailed,
                outcome: AuditOutcome::Failed,
                subject: $user,
                tenantUserId: $user->id,
                visibility: 'tenant',
                pii: [
                    'attempted_email' => $attemptedEmail,
                ],
                actorId: null,
            ));

            if ($user->status === 'pending') {
                throw ValidationException::withMessages([
                    'email' => [__('messages.auth.account_pending_verification')],
                ]);
            }

            if ($user->status === 'suspended') {
                throw ValidationException::withMessages([
                    'email' => [__('messages.auth.account_suspended')],
                ]);
            }

            throw ValidationException::withMessages([
                'email' => [__('messages.auth.account_inactive')],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        $this->logger()->record(new AuditEntry(
            action: AuditAction::AuthLoginSuccess,
            outcome: AuditOutcome::Success,
            subject: $user,
            tenantUserId: $user->id,
            visibility: 'tenant',
            pii: [
                'email' => $user->email,
            ],
        ));

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    /**
     * Update user profile attributes and verify credential change requests.
     *
     * @throws ValidationException
     */
    public function updateProfile(User $user, UpdateProfileDTO $dto): User
    {
        $oldEmail = $user->email;
        $emailChanged = strcasecmp($user->email, $dto->email) !== 0;
        $passwordChanged = $dto->password !== null && $dto->password !== '';

        $auditContext = app(AuditContext::class);
        /** @var mixed $userToken */
        $userToken = $user->currentAccessToken();
        $isImpersonated = $auditContext->getImpersonatorId() !== null
            || ($userToken instanceof PersonalAccessToken && $user->tokenCan('impersonated'));

        if ($isImpersonated) {
            if ($passwordChanged) {
                $this->logger()->recordIndependent(new AuditEntry(
                    action: AuditAction::AuthPasswordChanged,
                    outcome: AuditOutcome::Denied,
                    subject: $user,
                    tenantUserId: $user->id,
                    impersonatorId: $auditContext->getImpersonatorId(),
                    visibility: 'tenant',
                    context: [
                        'reason' => 'forbidden_while_impersonating',
                    ],
                ));

                abort(403, 'Changing password is forbidden while impersonating.');
            }

            if ($emailChanged) {
                $this->logger()->recordIndependent(new AuditEntry(
                    action: AuditAction::AuthEmailChanged,
                    outcome: AuditOutcome::Denied,
                    subject: $user,
                    tenantUserId: $user->id,
                    impersonatorId: $auditContext->getImpersonatorId(),
                    visibility: 'tenant',
                    context: [
                        'reason' => 'forbidden_while_impersonating',
                    ],
                ));

                abort(403, 'Changing email is forbidden while impersonating.');
            }
        }

        if ($emailChanged || $passwordChanged) {
            if ($dto->currentPassword === null || $dto->currentPassword === '' || ! Hash::check($dto->currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => [__('messages.auth.current_password_invalid')],
                ]);
            }
        }

        return DB::transaction(function () use ($user, $dto, $oldEmail, $emailChanged, $passwordChanged): User {
            if ($passwordChanged) {
                $user->password = Hash::make((string) $dto->password);

                // Revoke all tokens except the current one
                /** @var mixed $currentToken */
                $currentToken = $user->currentAccessToken();
                if (is_object($currentToken) && isset($currentToken->id)) {
                    $user->tokens()->where('id', '!=', $currentToken->id)->delete();
                }

                $this->logger()->record(new AuditEntry(
                    action: AuditAction::AuthPasswordChanged,
                    outcome: AuditOutcome::Success,
                    subject: $user,
                    tenantUserId: $user->id,
                    visibility: 'tenant',
                    pii: [
                        'email' => $user->email,
                    ],
                ));

                $this->logger()->record(new AuditEntry(
                    action: AuditAction::AuthTokensRevoked,
                    outcome: AuditOutcome::Success,
                    subject: $user,
                    tenantUserId: $user->id,
                    visibility: 'tenant',
                    context: [
                        'reason' => 'password',
                    ],
                ));
            }

            $user->name = $dto->name;

            if ($emailChanged) {
                $user->email = $dto->email;
                $user->email_verified_at = null;

                if (SystemSetting::isRegistrationVerificationEnabled()) {
                    $user->status = 'pending';
                }

                $user->save();

                $this->logger()->record(new AuditEntry(
                    action: AuditAction::AuthEmailChanged,
                    outcome: AuditOutcome::Success,
                    subject: $user,
                    tenantUserId: $user->id,
                    visibility: 'tenant',
                    pii: [
                        'old_email' => $oldEmail,
                        'new_email' => $user->email,
                    ],
                ));

                // Send verification notification to the updated email address
                $user->sendEmailVerificationNotification();
            } else {
                $user->save();
            }

            return $user->fresh(['roles', 'quota']) ?? $user;
        });
    }

    /**
     * Record password change when reset token is consumed.
     */
    public function recordPasswordReset(User $user): void
    {
        $this->logger()->record(new AuditEntry(
            action: AuditAction::AuthPasswordChanged,
            outcome: AuditOutcome::Success,
            subject: $user,
            tenantUserId: $user->id,
            visibility: 'tenant',
            pii: [
                'email' => $user->email,
            ],
        ));

        $this->logger()->record(new AuditEntry(
            action: AuditAction::AuthTokensRevoked,
            outcome: AuditOutcome::Success,
            subject: $user,
            tenantUserId: $user->id,
            visibility: 'tenant',
            context: [
                'reason' => 'password',
            ],
        ));
    }

    /**
     * Invalidate current access token session.
     */
    public function logout(User $user): void
    {
        $this->logger()->record(new AuditEntry(
            action: AuditAction::AuthLogout,
            outcome: AuditOutcome::Success,
            subject: $user,
            tenantUserId: $user->id,
            visibility: 'tenant',
            pii: [
                'email' => $user->email,
            ],
        ));

        /** @var mixed $currentAccessToken */
        $currentAccessToken = $user->currentAccessToken();
        if ($currentAccessToken instanceof PersonalAccessToken) {
            $currentAccessToken->delete();
        }
    }
}
