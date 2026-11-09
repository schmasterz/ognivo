<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['prefix' => 'api', 'middleware' => ['auth:sanctum']]);

Broadcast::channel('users.{id}', function (User $user, int $id): bool {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('admin.workloads', function (User $user): bool {
    return $user->hasRole('admin');
});
