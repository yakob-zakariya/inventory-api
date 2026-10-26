<?php

namespace App\Http\Requests\Api\V1\Concerns;

use Illuminate\Validation\Validator;

/**
 * For posted documents: only non-financial fields may change. Any other field in the body
 * is reported as a validation error instead of being silently ignored.
 */
trait EditableFields
{
    /**
     * @return list<string>
     */
    abstract protected function editableFields(): array;

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), $this->editableFields()) as $field) {
                $validator->errors()->add($field, "The {$field} cannot be changed. Only ".implode(' and ', $this->editableFields()).' can be edited; void the document to correct anything else.');
            }
        }];
    }
}
