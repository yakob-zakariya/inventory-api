<?php

namespace App\Http\Resources\Concerns;

use App\Enums\Permission;
use App\Models\Product;
use App\Services\SaleDraft;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Profit hints on previewed sale lines. Everyone sees whether a price is below cost plus
 * additional cost; only users with costs.view see the numbers.
 */
trait ShowsProfitHints
{
    /**
     * @param  array{product: Product, quantity: int, unit_price_cents: int, unit_cost_cents: int}  $line
     * @return array<string, mixed>
     */
    private function profitHints(Request $request, array $line): array
    {
        $profitCents = SaleDraft::estimatedProfitCents($line);

        return [
            'below_cost' => $profitCents < 0,
            ...($this->showsProfit($request) ? [
                'unit_cost' => Money::fromCents($line['unit_cost_cents']),
                'additional_cost' => $line['product']->additional_cost,
                'estimated_profit' => Money::fromCents($profitCents),
            ] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function totalProfitHint(Request $request, SaleDraft $draft): array
    {
        return $this->showsProfit($request)
            ? ['estimated_profit' => Money::fromCents($draft->totalEstimatedProfitCents())]
            : [];
    }

    private function showsProfit(Request $request): bool
    {
        return (bool) $request->user()?->can(Permission::CostsView->value);
    }
}
