<?php

declare(strict_types=1);

namespace App\Audit;

use App\DTO\Cast;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final class AuditContext
{
    private ?string $requestId = null;

    private ?string $ip = null;

    private ?string $userAgent = null;

    private string $actorType = 'user';

    private ?int $actorId = null;

    private ?string $actorLabel = null;

    private ?int $impersonatorId = null;

    private ?int $operationId = null;

    /**
     * Populate audit context from an incoming HTTP request.
     */
    public function fromRequest(Request $request): static
    {
        $headerReqId = Cast::string($request->header('X-Request-Id'));
        if ($headerReqId !== '') {
            $this->requestId = $headerReqId;
        } elseif ($this->requestId === null || $this->requestId === '') {
            $this->requestId = Str::uuid()->toString();
        }

        $this->ip = $request->ip();

        $ua = $request->userAgent();
        $this->userAgent = $ua !== null && $ua !== '' ? mb_substr($ua, 0, 255) : null;
        $this->actorType = 'user';

        $this->impersonatorId = null;

        $user = $request->user();
        if ($user !== null) {
            $this->actorId = Cast::int($user->getAuthIdentifier());
            $userLabel = Cast::string($user->email ?? $user->name);
            $this->actorLabel = $userLabel !== '' ? $userLabel : "User #{$this->actorId}";

            /** @var mixed $currentToken */
            $currentToken = $user->currentAccessToken();
            if ($currentToken instanceof PersonalAccessToken && $user->tokenCan('impersonated')) {
                $impId = null;
                $abilities = is_iterable($currentToken->abilities) ? $currentToken->abilities : [];
                foreach ($abilities as $ability) {
                    if (is_string($ability) && str_starts_with($ability, 'impersonator:')) {
                        $val = substr($ability, 13);
                        if (is_numeric($val)) {
                            $impId = (int) $val;
                            break;
                        }
                    }
                }
                if ($impId === null && isset($currentToken->name) && str_starts_with($currentToken->name, 'impersonation:')) {
                    $val = substr($currentToken->name, 14);
                    if (is_numeric($val)) {
                        $impId = (int) $val;
                    }
                }
                if ($impId !== null) {
                    $this->impersonatorId = $impId;
                    $request->attributes->set('impersonator_id', $impId);
                }
            }
        }

        $attrImpersonatorId = $request->attributes->get('impersonator_id');
        if ($attrImpersonatorId === null && $request->hasSession()) {
            $attrImpersonatorId = $request->session()->get('impersonator_id');
        }
        if ($attrImpersonatorId !== null) {
            $this->impersonatorId = Cast::intOrNull($attrImpersonatorId);
        }

        $opId = $request->header('X-Operation-Id') ?? $request->input('operation_id');
        $this->operationId = $opId !== null && is_numeric($opId) ? (int) $opId : null;

        return $this;
    }

    /**
     * Configure context for automated system actions.
     */
    public function forSystem(): static
    {
        $this->actorType = 'system';
        $this->actorId = null;
        $this->actorLabel = 'system';
        $this->impersonatorId = null;
        $this->requestId = Str::uuid()->toString();
        $this->ip = null;
        $this->userAgent = null;

        return $this;
    }

    /**
     * Serialize context to an array payload suitable for queue jobs or transport.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'request_id' => $this->getRequestId(),
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'actor_type' => $this->actorType,
            'actor_id' => $this->getActorId(),
            'actor_label' => $this->getActorLabel(),
            'impersonator_id' => $this->impersonatorId,
        ];

        if ($this->operationId !== null) {
            $payload['operation_id'] = $this->operationId;
        }

        return $payload;
    }

    /**
     * Restore audit context from an array payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fromPayload(array $payload, bool $isJob = false): static
    {
        $this->requestId = isset($payload['request_id']) ? Cast::string($payload['request_id']) : Str::uuid()->toString();
        $this->ip = isset($payload['ip']) ? Cast::stringOrNull($payload['ip']) : null;
        $this->userAgent = isset($payload['user_agent']) ? Cast::stringOrNull($payload['user_agent']) : null;
        $this->actorId = isset($payload['actor_id']) ? Cast::intOrNull($payload['actor_id']) : null;
        $this->actorLabel = isset($payload['actor_label']) ? Cast::stringOrNull($payload['actor_label']) : null;
        $this->impersonatorId = isset($payload['impersonator_id']) ? Cast::intOrNull($payload['impersonator_id']) : null;
        $this->operationId = isset($payload['operation_id']) ? Cast::intOrNull($payload['operation_id']) : null;

        if ($isJob) {
            $this->actorType = 'job';
        } else {
            $this->actorType = isset($payload['actor_type']) ? Cast::string($payload['actor_type'], 'user') : 'user';
        }

        return $this;
    }

    public function getRequestId(): string
    {
        if ($this->requestId === null || $this->requestId === '') {
            $this->requestId = Str::uuid()->toString();
        }

        return $this->requestId;
    }

    public function setRequestId(?string $requestId): static
    {
        $this->requestId = $requestId;

        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null;

        return $this;
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function setActorType(string $actorType): static
    {
        $this->actorType = $actorType;

        return $this;
    }

    public function getActorId(): ?int
    {
        if ($this->actorId !== null) {
            return $this->actorId;
        }

        $id = Auth::id();

        return Cast::intOrNull($id);
    }

    public function setActorId(?int $actorId): static
    {
        $this->actorId = $actorId;

        return $this;
    }

    public function getActorLabel(): ?string
    {
        if ($this->actorLabel !== null) {
            return $this->actorLabel;
        }

        $user = Auth::user();
        if ($user !== null) {
            $label = Cast::string($user->email ?? $user->name);
            $userId = Cast::string($user->getAuthIdentifier());

            return $label !== '' ? $label : "User #{$userId}";
        }

        return null;
    }

    public function setActorLabel(?string $actorLabel): static
    {
        $this->actorLabel = $actorLabel;

        return $this;
    }

    public function getImpersonatorId(): ?int
    {
        if ($this->impersonatorId !== null) {
            return $this->impersonatorId;
        }

        $user = Auth::user();
        if ($user instanceof User) {
            /** @var mixed $currentToken */
            $currentToken = $user->currentAccessToken();
            if ($currentToken instanceof PersonalAccessToken && $user->tokenCan('impersonated')) {
                $abilities = is_iterable($currentToken->abilities) ? $currentToken->abilities : [];
                foreach ($abilities as $ability) {
                    if (is_string($ability) && str_starts_with($ability, 'impersonator:')) {
                        $val = substr($ability, 13);
                        if (is_numeric($val)) {
                            $this->impersonatorId = (int) $val;

                            return $this->impersonatorId;
                        }
                    }
                }
                if (isset($currentToken->name) && str_starts_with($currentToken->name, 'impersonation:')) {
                    $val = substr($currentToken->name, 14);
                    if (is_numeric($val)) {
                        $this->impersonatorId = (int) $val;

                        return $this->impersonatorId;
                    }
                }
            }
        }

        return null;
    }

    public function setImpersonatorId(?int $impersonatorId): static
    {
        $this->impersonatorId = $impersonatorId;

        return $this;
    }

    public function getOperationId(): ?int
    {
        return $this->operationId;
    }

    public function setOperationId(?int $operationId): static
    {
        $this->operationId = $operationId;

        return $this;
    }
}
