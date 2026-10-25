<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * A request that is well-formed but breaks a business rule
 * (for example selling more than is in stock). Rendered as 422.
 */
class BusinessRuleException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
