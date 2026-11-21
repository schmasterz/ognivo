<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PoolAllocationConflictException extends Exception
{
    public function __construct(
        public readonly int $allocationId,
        public readonly string $ipAddress,
        string $message = '',
    ) {
        $msg = $message !== ''
            ? $message
            : sprintf("Cannot modify gateway or exclusions: address '%s' (allocation ID #%d) is currently allocated.", $ipAddress, $allocationId);
        parent::__construct($msg, 422);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'gateway' => [$this->getMessage()],
            ],
        ], 422);
    }
}
