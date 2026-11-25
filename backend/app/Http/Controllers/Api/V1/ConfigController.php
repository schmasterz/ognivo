<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\Cast;
use App\Http\Controllers\Controller;
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
        return response()->json([
            'app_name' => Cast::string(config('app.name', 'Ognivo'), 'Ognivo'),
            'app_env' => Cast::string(config('app.env', 'production'), 'production'),
            'api_base_url' => '/api/v1',
            'ws_host' => Cast::string(config('websockets.host', 'localhost'), 'localhost'),
            'ws_port' => Cast::int(config('websockets.port', 6001), 6001),
            'ws_key' => Cast::string(config('reverb.apps.apps.0.key', ''), ''),
            'features' => [
                'registration' => Cast::bool(config('auth.registration_enabled', false), false),
                'impersonation' => Cast::bool(config('auth.impersonation_enabled', true), true),
            ],
        ]);
    }
}
