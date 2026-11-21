<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PoolHasActiveAllocationsException extends Exception
{
    public function __construct(string $message = 'Cannot delete pool with active IP allocations.')
    {
        parent::__construct($message, 422);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'pool' => [$this->getMessage()],
            ],
        ], 422);
    }
}
