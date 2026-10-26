<?php

namespace App\Support;

use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * The dates a report covers (both days included) and how to group its series.
 * Reports total by day in SQL (portable across SQLite and MySQL) and fold days into months here.
 */
final readonly class ReportPeriod
{
    /**
     * @param  'day'|'month'|null  $groupBy
     */
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public ?string $groupBy = null,
    ) {}

    /**
     * The given dates, or the current month when they are missing.
     */
    public static function fromInput(?string $from, ?string $to, ?string $groupBy = null): self
    {
        return new self(
            $from ? Carbon::parse($from)->startOfDay() : now()->startOfMonth(),
            $to ? Carbon::parse($to)->startOfDay() : now()->endOfMonth()->startOfDay(),
            $groupBy,
        );
    }

    /**
     * The series label a day (YYYY-MM-DD) belongs to.
     */
    public function labelFor(string $day): string
    {
        return $this->groupBy === 'month' ? substr($day, 0, 7) : substr($day, 0, 10);
    }

    /**
     * Every label in the period, in order, so a series has no gaps.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        $labels = [];

        foreach (CarbonPeriod::create($this->from, '1 day', $this->to) as $day) {
            $labels[$this->labelFor($day->toDateString())] = true;
        }

        return array_keys($labels);
    }
}
