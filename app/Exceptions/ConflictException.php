<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * A request that clashes with the current state of a record
 * (for example deleting a category that still has products). Rendered as 409.
 */
class ConflictException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
