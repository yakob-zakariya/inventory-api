<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Money in and out of the accounts over a period, by transaction type.
 *
 * A reversal counts under the type it cancels, so a payment reversed in the same period nets to zero.
 * Across all accounts, transfers are left out: they only move money inside the shop.
 */
final readonly class CashFlowReport
{
    private const TRANSFER_TYPES = [AccountTransactionType::TransferIn->value, AccountTransactionType::TransferOut->value];

    /**
     * @param  array<string, array<string, int>>  $days  net cents per day and type
     */
    private function __construct(
        public ReportPeriod $period,
        public ?Account $account,
        public int $openingCents,
        public int $closingCents,
        private array $days,
    ) {}

    public static function for(ReportPeriod $period, ?Account $account = null): self
    {
        $type = 'coalesce(originals.type, entries.type)';

        $rows = self::entries($account)
            ->whereDate('entries.transaction_date', '>=', $period->from)
            ->whereDate('entries.transaction_date', '<=', $period->to)
            ->when($account === null, fn (Builder $query) => $query->whereRaw("{$type} not in (?, ?)", self::TRANSFER_TYPES))
            ->selectRaw('substr(entries.transaction_date, 1, 10) as day')
            ->selectRaw("{$type} as type")
            ->selectRaw('sum(round(entries.amount * 100)) as amount')
            ->groupBy(DB::raw('substr(entries.transaction_date, 1, 10)'), DB::raw($type))
            ->toBase()
            ->get();

        $days = [];

        foreach ($rows as $row) {
            $days[$row->day][$row->type] = (int) $row->amount;
        }

        return new self(
            $period,
            $account,
            self::balanceCents($account, fn (Builder $query) => $query->whereDate('entries.transaction_date', '<', $period->from)),
            self::balanceCents($account, fn (Builder $query) => $query->whereDate('entries.transaction_date', '<=', $period->to)),
            $days,
        );
    }

    /**
     * Money in and out per type over the whole period: each type's net decides its side.
     *
     * @return array<string, array{in: int, out: int}>
     */
    public function byType(): array
    {
        $net = [];

        foreach ($this->days as $types) {
            foreach ($types as $type => $cents) {
                $net[$type] = ($net[$type] ?? 0) + $cents;
            }
        }

        $byType = [];

        foreach (AccountTransactionType::cases() as $type) {
            if (array_key_exists($type->value, $net)) {
                $byType[$type->value] = self::split($net[$type->value]);
            }
        }

        return $byType;
    }

    /**
     * @return array{in: int, out: int}
     */
    public function totals(): array
    {
        return self::sum($this->byType());
    }

    /**
     * Money in and out per day or month, every period included.
     *
     * @return array<string, array{in: int, out: int}>
     */
    public function series(): array
    {
        $net = array_fill_keys($this->period->labels(), []);

        foreach ($this->days as $day => $types) {
            $label = $this->period->labelFor($day);

            foreach ($types as $type => $cents) {
                $net[$label][$type] = ($net[$label][$type] ?? 0) + $cents;
            }
        }

        return array_map(fn (array $types) => self::sum(array_map(self::split(...), $types)), $net);
    }

    /**
     * Ledger rows joined to the entry each reversal cancels, for one account or all.
     *
     * @return Builder<AccountTransaction>
     */
    private static function entries(?Account $account): Builder
    {
        return AccountTransaction::query()
            ->from('account_transactions as entries')
            ->leftJoin('account_transactions as originals', 'originals.id', '=', 'entries.reverses_transaction_id')
            ->when($account, fn (Builder $query) => $query->where('entries.account_id', $account->id));
    }

    /**
     * Opening balances plus the ledger rows the constraint selects, in cents.
     *
     * @param  callable(Builder<AccountTransaction>): mixed  $constraint
     */
    private static function balanceCents(?Account $account, callable $constraint): int
    {
        $opening = Account::query()
            ->when($account, fn (Builder $query) => $query->whereKey($account->id))
            ->sum(DB::raw('round(opening_balance * 100)'));

        $entries = self::entries($account)->tap($constraint)->sum(DB::raw('round(entries.amount * 100)'));

        return (int) $opening + (int) $entries;
    }

    /**
     * @return array{in: int, out: int}
     */
    private static function split(int $netCents): array
    {
        return ['in' => max($netCents, 0), 'out' => max(-$netCents, 0)];
    }

    /**
     * @param  array<array{in: int, out: int}>  $amounts
     * @return array{in: int, out: int}
     */
    private static function sum(array $amounts): array
    {
        return [
            'in' => array_sum(array_column($amounts, 'in')),
            'out' => array_sum(array_column($amounts, 'out')),
        ];
    }
}
