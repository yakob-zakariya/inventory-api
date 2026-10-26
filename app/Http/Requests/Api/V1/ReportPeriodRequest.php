<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Account;
use App\Support\ReportPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The period of a report: `from` and `to` (default the current month), an optional
 * `group_by` (day or month) for a series, and for cash flow an optional `account_id`.
 */
class ReportPeriodRequest extends FormRequest
{
    /**
     * A day-by-day series is limited to about a year.
     */
    private const MAX_DAYS_BY_DAY = 366;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['sometimes', Rule::in(['day', 'month'])],
            'account_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Account::class, 'id')],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->input('group_by') !== 'day') {
                return;
            }

            $period = $this->period();

            if ($period->from->diffInDays($period->to) >= self::MAX_DAYS_BY_DAY) {
                $validator->errors()->add('group_by', 'Group by month for a period longer than a year.');
            }
        }];
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::fromInput($this->input('from'), $this->input('to'), $this->input('group_by'));
    }

    public function account(): ?Account
    {
        return $this->validated('account_id') ? Account::find($this->validated('account_id')) : null;
    }
}
