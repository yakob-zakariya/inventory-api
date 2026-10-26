<?php

namespace App\Http\Resources;

use App\Services\CashFlowReport;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CashFlowReport $resource
 */
class CashFlowReportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource;
        $totals = $report->totals();

        return [
            'from' => $report->period->from->toDateString(),
            'to' => $report->period->to->toDateString(),
            'account' => $report->account?->only(['id', 'name']),
            'opening_balance' => Money::fromCents($report->openingCents),
            'money_in' => Money::fromCents($totals['in']),
            'money_out' => Money::fromCents($totals['out']),
            'closing_balance' => Money::fromCents($report->closingCents),
            'by_type' => collect($report->byType())
                ->map(fn (array $cents, string $type) => ['type' => $type, ...static::amounts($cents)])
                ->values(),
            'series' => $this->when($report->period->groupBy !== null, fn () => collect($report->series())
                ->map(fn (array $cents, string $period) => ['period' => $period, ...static::amounts($cents)])
                ->values()),
        ];
    }

    /**
     * @param  array{in: int, out: int}  $cents
     * @return array{in: string, out: string}
     */
    private static function amounts(array $cents): array
    {
        return ['in' => Money::fromCents($cents['in']), 'out' => Money::fromCents($cents['out'])];
    }
}
