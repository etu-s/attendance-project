<?php

declare(strict_types=1);

namespace App\Modules\Core\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A request that is valid in shape but breaks a business rule (e.g. removing
 * the last Tenant Admin). The message is written for end users and is safe
 * to return to the client.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $rule,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'rule' => $this->rule,
        ], $this->status);
    }
}
