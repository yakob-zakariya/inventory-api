<?php

namespace App\Http\Resources;

use App\Services\ProfitReport;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property ProfitReport $resource
 */
class ProfitReportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource;

        return [
            'from' => $report->period->from->toDateString(),
            'to' => $report->period->to->toDateString(),
            ...static::amounts($report->totals()),
            'series' => $this->when($report->period->groupBy !== null, fn () => collect($report->series())
                ->map(fn (array $cents, string $period) => ['period' => $period, ...static::amounts($cents)])
                ->values()),
        ];
    }

    /**
     * @param  array{sales: int, cost: int, expenses: int}  $cents
     * @return array<string, string>
     */
    private static function amounts(array $cents): array
    {
        $gross = $cents['sales'] - $cents['cost'];

        return [
            'sales' => Money::fromCents($cents['sales']),
            'cost_of_sales' => Money::fromCents($cents['cost']),
            'gross_profit' => Money::fromCents($gross),
            'expenses' => Money::fromCents($cents['expenses']),
            'net_profit' => Money::fromCents($gross - $cents['expenses']),
        ];
    }
}
