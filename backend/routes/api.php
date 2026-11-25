<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ConfigController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| HTTP API routes are automatically registered via PHP 8 Route Attributes
| (spatie/laravel-route-attributes) directly declared on Controller classes.
|
*/

Route::get('/v1/config', ConfigController::class);
