<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemHealthController extends Controller
{
    public function __construct(
        protected SystemHealthService $healthService
    ) {}

    public function index(): View
    {
        $metrics = $this->healthService->getHealthMetrics(includeSensitiveData: true);

        return view('admin.health.index', compact('metrics'));
    }

    public function data(): JsonResponse
    {
        $metrics = $this->healthService->getHealthMetrics(includeSensitiveData: false);

        return response()->json($metrics);
    }
}
