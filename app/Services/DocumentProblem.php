<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;

/**
 * A business rule that would stop a new document from being saved. Saving raises the first one
 * as a 422; a preview lists them all, next to the totals.
 */
final readonly class DocumentProblem
{
    /**
     * @param  'overpaid'|'walk_in_not_paid'|'insufficient_stock'  $rule
     * @param  ?string  $field  the request field to point at, if one clearly applies
     */
    public function __construct(
        public string $rule,
        public string $message,
        public ?string $field = null,
    ) {}

    public function toException(): BusinessRuleException
    {
        return new BusinessRuleException($this->message);
    }
}
