<?php

declare(strict_types=1);

namespace App\Providers;

use App\Audit\AuditConfigValidator;
use App\Audit\AuditContext;
use App\Audit\AuditLogger;
use App\Contracts\BackupTargetInterface;
use App\Listeners\AuditLockoutListener;
use App\Listeners\HostStateChangedListener;
use App\Listeners\SnapshotStateChangedListener;
use App\Listeners\VmStateChangedListener;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Policies\VirtualMachinePolicy;
use App\Services\Backup\LocalSnapshotTarget;
use App\Services\Hypervisor\Contracts\IncusEventTransportInterface;
use App\Services\Hypervisor\TlsPeerCertificateFetcher;
use App\Services\Hypervisor\TlsPeerCertificateFetcherInterface;
use App\Services\Hypervisor\Transports\WebSocketIncusEventTransport;
use App\Services\SystemOverviewService;
use App\Support\Cast;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Override;
use Spatie\ModelStates\Events\StateChanged;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        $this->app->scoped(AuditContext::class, static fn (): AuditContext => new AuditContext);
        $this->app->singleton(AuditLogger::class, static fn (Application $app): AuditLogger => new AuditLogger($app->make(AuditContext::class)));

        $this->app->bind(TlsPeerCertificateFetcherInterface::class, TlsPeerCertificateFetcher::class);
        $this->app->bind(
            IncusEventTransportInterface::class,
            WebSocketIncusEventTransport::class
        );
        $this->app->bind(BackupTargetInterface::class, LocalSnapshotTarget::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Browser-only API: Bearer tokens are not accepted until external API access is designed; remove this callback to re-enable.
        Sanctum::getAccessTokenFromRequestUsing(static fn (): ?string => null);

        Queue::createPayloadUsing(static function (): array {
            return [
                'audit_context' => app(AuditContext::class)->toPayload(),
            ];
        });

        Queue::before(static function (JobProcessing $event): void {
            $payload = $event->job->payload();
            if (isset($payload['audit_context']) && is_array($payload['audit_context'])) {
                /** @var array<string, mixed> $auditPayload */
                $auditPayload = $payload['audit_context'];
                app(AuditContext::class)->fromPayload($auditPayload, isJob: true);
            }
        });

        Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
            if (str_starts_with($event->command, 'audit:') || $event->command === 'audit') {
                AuditConfigValidator::validateProductionAuditCommand($event->command);
            }
        });

        Event::listen(MigrationsEnded::class, static function (): void {
            if (config('database.default') === 'mysql' || config('database.connections.migration') !== null) {
                try {
                    Artisan::call('audit:grants', ['--force' => true]);
                } catch (\Throwable) {
                    // Ignore if connection or schema not yet initialized
                }
            }
        });

        Gate::policy(VirtualMachine::class, VirtualMachinePolicy::class);
        Gate::define('vps.plans.manage', static fn (User $user): bool => $user->hasRole('admin'));
        Gate::define('vps.plan_change.manage', static fn (User $user): bool => $user->hasRole('admin'));
        Gate::define('vps.plan_change.request', static fn (User $user): bool => true);
        Gate::define('viewApiDocs', static function (?User $user = null): bool {
            if (app()->environment('local', 'testing')) {
                return true;
            }

            if ((bool) config('scramble.allow_public', true)) {
                return true;
            }

            $resolvedUser = $user ?? request()->user('sanctum');

            return $resolvedUser instanceof User && $resolvedUser->hasRole('admin');
        });

        Event::listen(Lockout::class, AuditLockoutListener::class);
        Event::listen(StateChanged::class, VmStateChangedListener::class);
        Event::listen(StateChanged::class, SnapshotStateChangedListener::class);
        Event::listen(StateChanged::class, HostStateChangedListener::class);

        // Configure named rate limiter for authentication endpoints
        RateLimiter::for('auth-login', static function (Request $request): Limit {
            $email = strtolower(Cast::string($request->input('email', '')));
            $ip = Cast::string($request->ip());

            return Limit::perMinute(5)
                ->by($email.'|'.$ip)
                ->response(static function (Request $request, array $headers): JsonResponse {
                    event(new Lockout($request));

                    $retryAfter = Cast::int($headers['Retry-After'] ?? 60, 60);

                    return response()->json([
                        'message' => __('passwords.throttled', ['seconds' => $retryAfter]),
                    ], 429, $headers);
                });
        });

        RateLimiter::for('auth-register', static function (Request $request): Limit {
            return Limit::perHour(5)->by(Cast::string($request->ip()));
        });

        // Configure named rate limiter for workload lifecycle actions
        RateLimiter::for('vm-actions', static function (Request $request): Limit {
            /** @var User|null $user */
            $user = $request->user();

            // Administrators are never throttled during system operations
            if ($user !== null && $user->hasRole('admin')) {
                return Limit::none();
            }

            $maxAttempts = Cast::int(config('app.rate_limits.vm_actions', 10), 10);
            $decayMinutes = Cast::int(config('app.rate_limits.vm_actions_decay_minutes', 1), 1);

            // Isolate rate limiting per tenant ID (or IP address fallback)
            $key = $user !== null ? 'user:'.$user->id : 'ip:'.$request->ip();

            return Limit::perMinutes($decayMinutes, $maxAttempts)
                ->by($key)
                ->response(static function (Request $request, array $headers): JsonResponse {
                    $retryAfter = Cast::int($headers['Retry-After'] ?? 60, 60);

                    return response()->json([
                        'message' => __('messages.vm.rate_limit_exceeded', ['seconds' => $retryAfter]),
                        'retry_after' => $retryAfter,
                    ], 429, $headers);
                });
        });

        // Configure named rate limiter for workload telemetry queries (NFR-6)
        RateLimiter::for('vm-metrics', static function (Request $request): Limit {
            /** @var User|null $user */
            $user = $request->user();
            $maxAttempts = max(1, Cast::int(config('metrics.rate_limit_per_minute', 60), 60));
            $key = $user !== null ? 'metrics:user:'.$user->id : 'metrics:ip:'.Cast::string($request->ip());

            return Limit::perMinute($maxAttempts)
                ->by($key)
                ->response(static function (Request $request, array $headers): JsonResponse {
                    $retryAfter = Cast::int($headers['Retry-After'] ?? 60, 60);

                    return response()->json([
                        'message' => __('messages.vm.rate_limit_exceeded', ['seconds' => $retryAfter]),
                        'retry_after' => $retryAfter,
                    ], 429, $headers);
                });
        });

        // Configure named rate limiter for plan change preview and target listing endpoints
        RateLimiter::for('plan-change-preview', static function (Request $request): Limit {
            /** @var User|null $user */
            $user = $request->user();

            if ($user !== null && $user->hasRole('admin')) {
                return Limit::none();
            }

            $maxAttempts = max(1, Cast::int(config('app.rate_limits.plan_change_preview', 30), 30));
            $key = $user !== null
                ? 'plan-change-preview:user:'.$user->id
                : 'plan-change-preview:ip:'.Cast::string($request->ip());

            return Limit::perMinute($maxAttempts)
                ->by($key)
                ->response(static function (Request $request, array $headers): JsonResponse {
                    $retryAfter = Cast::int($headers['Retry-After'] ?? 60, 60);

                    return response()->json([
                        'message' => __('messages.vm.rate_limit_exceeded', ['seconds' => $retryAfter]),
                        'retry_after' => $retryAfter,
                    ], 429, $headers);
                });
        });

        // Password reset links must point to the web frontend, not the API host
        ResetPassword::createUrlUsing(
            static function (mixed $notifiable, string $token): string {
                $base = SystemSetting::getFrontendUrl();
                $email = $notifiable instanceof CanResetPassword
                    ? $notifiable->getEmailForPasswordReset()
                    : '';

                return $base.'/reset-password?'.http_build_query([
                    'token' => $token,
                    'email' => $email,
                ]);
            }
        );

        // Email verification links must point to the web frontend, not the API host
        VerifyEmail::createUrlUsing(
            static function (mixed $notifiable): string {
                $base = SystemSetting::getFrontendUrl();
                $id = $notifiable instanceof User ? Cast::string($notifiable->getKey()) : '';
                $hash = $notifiable instanceof User ? sha1($notifiable->getEmailForVerification()) : '';
                $expires = Cast::string(now()->addHours(24)->timestamp);
                $appKey = Cast::string(config('app.key'));
                $signature = hash_hmac('sha256', "verify:{$id}:{$hash}:{$expires}", $appKey);

                return $base.'/verify-email?'.http_build_query([
                    'id' => $id,
                    'hash' => $hash,
                    'expires' => $expires,
                    'signature' => $signature,
                ]);
            }
        );

        try {
            if (Schema::hasTable('system_settings')) {
                /** @var SystemOverviewService $overviewService */
                $overviewService = $this->app->make(SystemOverviewService::class);
                $overviewService->applyMailConfig();
            }
        } catch (\Throwable) {
            // Silently ignore if database connection or migrations are not yet ready
        }
    }
}
