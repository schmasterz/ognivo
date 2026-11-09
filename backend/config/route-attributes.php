<?php

declare(strict_types=1);
use Illuminate\Routing\Middleware\SubstituteBindings;

return [
    /*
     * Automatic registration of routes will only happen if this setting is `true`
     */
    'enabled' => true,

    /*
     * Controllers in these directories that have routing attributes
     * will automatically be registered.
     */
    'directories' => [
        app_path('Http/Controllers') => [
            'prefix' => 'api',
            'middleware' => 'api',
        ],
    ],

    /*
     * This middleware will be applied to all routes.
     */
    'middleware' => [
        SubstituteBindings::class,
    ],

    /*
     * When enabled, implicitly scoped bindings will be enabled by default.
     */
    'scope-bindings' => null,
];
