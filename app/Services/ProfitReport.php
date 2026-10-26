<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\DocumentStatus;
use App\Models\AccountTransaction;
use App\Models\SaleItem;
use App\Support\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Simple profit for a period: sales at their item prices, minus the item costs copied at the
 * time of sale, minus net expenses (expenses less their reversals, each on its own date).
 * Voided sales are left out.
 */
final readonly class ProfitReport
{
    /**
     * @param  array<string, array{sales: int, cost: int, expenses: int}>  $days  cents per day
     */
    private function __construct(
        public ReportPeriod $period,
        private array $days,
    ) {}

    public static function for(ReportPeriod $period): self
    {
        $days = [];

        $sales = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', DocumentStatus::Active->value)
            ->whereDate('sales.sale_date', '>=', $period->from)
            ->whereDate('sales.sale_date', '<=', $period->to)
            ->selectRaw('substr(sales.sale_date, 1, 10) as day')
            ->selectRaw('sum(sale_items.quantity * round(sale_items.unit_price * 100)) as sales')
            ->selectRaw('sum(sale_items.quantity * round(sale_items.unit_cost * 100)) as cost')
            ->groupBy(DB::raw('substr(sales.sale_date, 1, 10)'))
            ->toBase()
            ->get();

        foreach ($sales as $row) {
            $days[$row->day] = ['sales' => (int) $row->sales, 'cost' => (int) $row->cost, 'expenses' => 0];
        }

        $expenses = AccountTransaction::query()
            ->from('account_transactions as entries')
            ->leftJoin('account_transactions as originals', 'originals.id', '=', 'entries.reverses_transaction_id')
            ->where(fn ($query) => $query
                ->where('entries.type', AccountTransactionType::Expense->value)
                ->orWhere('originals.type', AccountTransactionType::Expense->value))
            ->whereDate('entries.transaction_date', '>=', $period->from)
            ->whereDate('entries.transaction_date', '<=', $period->to)
            ->selectRaw('substr(entries.transaction_date, 1, 10) as day')
            ->selectRaw('sum(round(entries.amount * 100)) as amount')
            ->groupBy(DB::raw('substr(entries.transaction_date, 1, 10)'))
            ->toBase()
            ->get();

        foreach ($expenses as $row) {
            $days[$row->day] ??= ['sales' => 0, 'cost' => 0, 'expenses' => 0];
            // Expenses are money out (negative); their reversals bring it back.
            $days[$row->day]['expenses'] = -(int) $row->amount;
        }

        return new self($period, $days);
    }

    /**
     * @return array{sales: int, cost: int, expenses: int}
     */
    public function totals(): array
    {
        $total = ['sales' => 0, 'cost' => 0, 'expenses' => 0];

        foreach ($this->days as $cents) {
            foreach ($cents as $key => $value) {
                $total[$key] += $value;
            }
        }

        return $total;
    }

    /**
     * Totals per day or month, every period included.
     *
     * @return array<string, array{sales: int, cost: int, expenses: int}>
     */
    public function series(): array
    {
        $series = array_fill_keys($this->period->labels(), ['sales' => 0, 'cost' => 0, 'expenses' => 0]);

        foreach ($this->days as $day => $cents) {
            $label = $this->period->labelFor($day);

            foreach ($cents as $key => $value) {
                $series[$label][$key] += $value;
            }
        }

        return $series;
    }
}
