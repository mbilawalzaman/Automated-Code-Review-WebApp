<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\DashboardService;
use App\Services\Admin\TrendAnalyticsService;
use Illuminate\View\View;

class DashboardController extends AdminController
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly TrendAnalyticsService $trendAnalyticsService,
    ) {}

    public function index(): View
    {
        return view('admin.dashboard', [
            'summary' => $this->dashboardService->getSummary(),
            'trends' => $this->trendAnalyticsService->dashboardTrends(14),
        ]);
    }
}
