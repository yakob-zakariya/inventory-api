<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReportPeriodRequest;
use App\Http\Resources\BusinessSummaryResource;
use App\Http\Resources\CashFlowReportResource;
use App\Http\Resources\ProfitReportResource;
use App\Services\BusinessSummary;
use App\Services\CashFlowReport;
use App\Services\ProfitReport;

class ReportController extends Controller
{
    /**
     * Money, inventory, receivables, payables and net worth, as of now.
     */
    public function summary(): BusinessSummaryResource
    {
        return new BusinessSummaryResource(BusinessSummary::now());
    }

    /**
     * Sales, cost of sales, expenses and profit over a period.
     */
    public function profit(ReportPeriodRequest $request): ProfitReportResource
    {
        return new ProfitReportResource(ProfitReport::for($request->period()));
    }

    /**
     * Opening and closing balance and the money in and out over a period, for all accounts or one.
     */
    public function cashFlow(ReportPeriodRequest $request): CashFlowReportResource
    {
        return new CashFlowReportResource(CashFlowReport::for($request->period(), $request->account()));
    }
}
