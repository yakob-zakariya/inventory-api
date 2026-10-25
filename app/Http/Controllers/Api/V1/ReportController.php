<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessSummaryResource;
use App\Services\BusinessSummary;

class ReportController extends Controller
{
    /**
     * Money, inventory, receivables, payables and net worth, as of now.
     */
    public function summary(): BusinessSummaryResource
    {
        return new BusinessSummaryResource(BusinessSummary::now());
    }
}
