<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Cast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller exposing public runtime configuration for the frontend and external clients.
 */
final class ConfigController extends Controller
{
    /**
     * Return public runtime configuration and feature flags.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $publicHost = config('reverb.public_host') ?? config('broadcasting.public_host');
        $wsHost = is_string($publicHost) && trim($publicHost) !== '' ? trim($publicHost) : null;

        $publicPort = config('reverb.public_port') ?? config('broadcasting.public_port');
        $wsPort = is_numeric($publicPort) ? (int) $publicPort : null;

        return response()->json([
            'app_name' => Cast::string(config('app.name', 'Ognivo'), 'Ognivo'),
            'app_env' => Cast::string(config('app.env', 'production'), 'production'),
            'api_base_url' => '/api/v1',
            'ws_host' => $wsHost,
            'ws_port' => $wsPort,
            'ws_key' => Cast::string(config('reverb.apps.apps.0.key', ''), ''),
            'features' => [
                'registration' => Cast::bool(config('auth.registration_enabled', false), false),
                'impersonation' => Cast::bool(config('auth.impersonation_enabled', true), true),
                'fake_driver' => Cast::bool(config('app.allow_fake_driver', false), false),
            ],
        ]);
    }
}
