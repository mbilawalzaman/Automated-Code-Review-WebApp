<?php

namespace App\Http\Controllers\Admin;

use App\Models\Report;
use App\Services\Admin\ReportPdfService;
use App\Services\Admin\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class ReportController extends AdminController
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly ReportPdfService $reportPdfService,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.reports.index', [
            'reports' => $this->reportService->list($this->perPage($request)),
        ]);
    }

    public function data(): JsonResponse
    {
        return DataTables::eloquent(Report::query()->with(['repository.project', 'generator'])->latest())
            ->filter(function ($query) {
                if ($keyword = request('search.value')) {
                    $query->where(function ($q) use ($keyword) {
                        $q->where('type', 'like', "%{$keyword}%")
                          ->orWhere('file_path', 'like', "%{$keyword}%")
                          ->orWhere('format', 'like', "%{$keyword}%")
                          ->orWhereHas('repository', function ($sub) use ($keyword) {
                              $sub->where('full_name', 'like', "%{$keyword}%")
                                  ->orWhere('name', 'like', "%{$keyword}%");
                          })
                          ->orWhereHas('project', function ($sub) use ($keyword) {
                              $sub->where('name', 'like', "%{$keyword}%");
                          })
                          ->orWhereHas('generator', function ($sub) use ($keyword) {
                              $sub->where('name', 'like', "%{$keyword}%");
                          });
                    });
                }
            })
            ->addColumn('report_formatted', function ($report) {
                $path = e($report->file_path ?? 'No file generated yet');
                return '<div class="font-semibold text-gray-900 text-sm">' . e(str($report->type)->replace('_', ' ')->title()) . '</div>' .
                       '<div class="font-mono text-xs text-gray-500 truncate max-w-xs" title="' . $path . '">' . $path . '</div>';
            })
            ->addColumn('repository_formatted', function ($report) {
                $repo = e($report->repository?->full_name ?? 'All repositories');
                $proj = e($report->project?->name ?? '');
                return '<div class="font-medium text-gray-900 text-sm break-words">' . $repo . '</div>' .
                       ($proj ? '<div class="text-xs text-gray-500 mt-0.5">' . $proj . '</div>' : '');
            })
            ->addColumn('format', function ($report) {
                return e(strtoupper($report->format));
            })
            ->addColumn('generator', function ($report) {
                return e($report->generator?->name ?? 'System');
            })
            ->addColumn('created_at_formatted', function ($report) {
                return e($report->created_at?->format('M d, Y H:i'));
            })
            ->addColumn('actions', function ($report) {
                return view('admin.reports.partials.actions', compact('report'))->render();
            })
            ->rawColumns(['report_formatted', 'repository_formatted', 'actions'])
            ->toJson();
    }

    /**
     * Download the Report PDF file.
     */
    public function download(Report $report): Response|RedirectResponse
    {
        try {
            $pdfContent = $this->reportPdfService->getOrGeneratePdf($report);
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.reports.index')
                ->withErrors(['report' => 'This report cannot be generated because its underlying review record is missing.']);
        }

        $fileName = "review-report-{$report->id}.pdf";

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /**
     * View/Stream the Report PDF file inline in browser.
     */
    public function stream(Report $report): Response|RedirectResponse
    {
        try {
            $pdfContent = $this->reportPdfService->getOrGeneratePdf($report);
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.reports.index')
                ->withErrors(['report' => 'This report cannot be generated because its underlying review record is missing.']);
        }

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
        ]);
    }
}
