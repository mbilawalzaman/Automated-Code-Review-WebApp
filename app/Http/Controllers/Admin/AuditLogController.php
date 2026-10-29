<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Services\Admin\AuditAnalyticsService;
use App\Services\Admin\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AuditLogController extends AdminController
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AuditAnalyticsService $auditAnalyticsService,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.audit-logs.index', [
            'auditLogs' => $this->auditLogService->list($this->perPage($request)),
            'categories' => AuditCategory::cases(),
            'eventTypes' => AuditEventType::cases(),
            'severities' => AuditSeverity::cases(),
            'activeTab' => 'ledger',
        ]);
    }

    public function analytics(Request $request): View
    {
        $analytics = $this->auditAnalyticsService->getAnalyticsData();

        return view('admin.audit-logs.analytics', [
            'analytics' => $analytics,
            'activeTab' => 'analytics',
        ]);
    }

    public function analyticsData(): JsonResponse
    {
        return response()->json($this->auditAnalyticsService->getAnalyticsData());
    }

    public function data(Request $request): JsonResponse
    {
        $query = AuditLog::query()->with('user')->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('date_from'))->startOfDay());
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('date_to'))->endOfDay());
        }

        return DataTables::eloquent($query)
            ->filterColumn('action_formatted', function ($q, $keyword) {
                $q->where(function ($sub) use ($keyword) {
                    $sub->where('action', 'like', "%{$keyword}%")
                        ->orWhere('ip_address', 'like', "%{$keyword}%");
                });
            })
            ->filterColumn('actor', function ($q, $keyword) {
                $q->whereHas('user', function ($sub) use ($keyword) {
                    $sub->where('name', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%");
                });
            })
            ->addColumn('action_formatted', function ($log) {
                return '<div class="font-medium text-gray-900 dark:text-white">' . e($log->action) . '</div>' .
                       '<div class="text-xs text-gray-500 dark:text-gray-400">' . e($log->ip_address ?? '127.0.0.1') . '</div>';
            })
            ->addColumn('category_badge', function ($log) {
                $label = $log->category instanceof AuditCategory ? $log->category->label() : ucfirst((string) $log->category);
                return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">' . e($label) . '</span>';
            })
            ->addColumn('severity_badge', function ($log) {
                $severityEnum = $log->severity instanceof AuditSeverity ? $log->severity : AuditSeverity::Info;
                return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' . $severityEnum->badgeClass() . '">' . e($severityEnum->label()) . '</span>';
            })
            ->addColumn('actor', function ($log) {
                return e($log->user?->name ?? 'System');
            })
            ->addColumn('target', function ($log) {
                if (! $log->target_type) {
                    return '<span class="text-xs text-gray-400">N/A</span>';
                }
                return '<div>' . e(class_basename($log->target_type)) . '</div>' .
                       '<div class="text-xs text-gray-500">ID ' . e((string) $log->target_id) . '</div>';
            })
            ->addColumn('created_at_formatted', function ($log) {
                return e($log->created_at?->format('M d, Y H:i:s'));
            })
            ->addColumn('actions', function ($log) {
                return '<button type="button" onclick="viewAuditDetails(' . $log->id . ')" class="inline-flex items-center px-3 py-1.5 border border-gray-300 dark:border-gray-600 shadow-sm text-xs font-medium rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">View Details</button>';
            })
            ->rawColumns(['action_formatted', 'category_badge', 'severity_badge', 'target', 'actions'])
            ->toJson();
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        $auditLog->load('user');

        $dateStr = $auditLog->created_at ? $auditLog->created_at->format('Ymd') : date('Ymd');
        $auditRef = 'AUD-' . $dateStr . '-' . str_pad((string) $auditLog->id, 6, '0', STR_PAD_LEFT);

        return response()->json([
            'id' => $auditLog->id,
            'audit_ref' => $auditRef,
            'action' => $auditLog->action,
            'category' => $auditLog->category instanceof AuditCategory ? $auditLog->category->label() : (string) $auditLog->category,
            'event_type' => $auditLog->event_type instanceof AuditEventType ? $auditLog->event_type->label() : (string) $auditLog->event_type,
            'severity' => $auditLog->severity instanceof AuditSeverity ? $auditLog->severity->label() : (string) $auditLog->severity,
            'actor' => $auditLog->user?->name ?? 'System',
            'actor_email' => $auditLog->user?->email ?? 'N/A',
            'ip_address' => $auditLog->ip_address ?? '127.0.0.1',
            'user_agent' => $auditLog->user_agent ?? 'System / Automated Process',
            'browser_summary' => $this->parseUserAgent($auditLog->user_agent),
            'target_type' => $auditLog->target_type ? class_basename($auditLog->target_type) : 'N/A',
            'target_id' => $auditLog->target_id ?? 'N/A',
            'reason' => $auditLog->reason,
            'before_state' => $auditLog->before_state,
            'after_state' => $auditLog->after_state,
            'metadata' => $auditLog->metadata,
            'request_uri' => $auditLog->metadata['request_uri'] ?? $auditLog->metadata['url'] ?? 'POST /admin/' . strtolower((string) ($auditLog->category?->value ?? 'system')),
            'created_at' => $auditLog->created_at?->format('M d, Y H:i:s T'),
        ]);
    }

    private function parseUserAgent(?string $ua): string
    {
        if (! $ua) {
            return 'System / Internal Worker';
        }
        if (str_contains($ua, 'Chrome')) {
            return 'Chrome on Linux (Desktop)';
        }
        if (str_contains($ua, 'Firefox')) {
            return 'Firefox (Desktop)';
        }
        if (str_contains($ua, 'Safari')) {
            return 'Safari';
        }
        return 'Web Browser';
    }

    public function export(Request $request)
    {
        $format = strtolower((string) $request->input('format', 'csv'));

        $query = AuditLog::query()->with('user')->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        $logs = $query->limit(5000)->get();

        if ($format === 'json') {
            $filename = 'audit_logs_' . date('Y_m_d_His') . '.json';
            return response()->streamDownload(function () use ($logs) {
                echo json_encode($logs, JSON_PRETTY_PRINT);
            }, $filename, ['Content-Type' => 'application/json']);
        }

        $filename = 'audit_logs_' . date('Y_m_d_His') . '.csv';
        return response()->streamDownload(function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Audit Ref', 'Category', 'Event Type', 'Severity', 'Action', 'Actor', 'Target Type', 'Target ID', 'IP Address', 'Timestamp']);

            foreach ($logs as $log) {
                $ref = 'AUD-' . ($log->created_at ? $log->created_at->format('Ymd') : date('Ymd')) . '-' . str_pad((string) $log->id, 6, '0', STR_PAD_LEFT);
                fputcsv($handle, [
                    $ref,
                    $log->category instanceof AuditCategory ? $log->category->value : (string) $log->category,
                    $log->event_type instanceof AuditEventType ? $log->event_type->value : (string) $log->event_type,
                    $log->severity instanceof AuditSeverity ? $log->severity->value : (string) $log->severity,
                    $log->action,
                    $log->user?->email ?? 'System',
                    $log->target_type ? class_basename($log->target_type) : 'N/A',
                    $log->target_id ?? 'N/A',
                    $log->ip_address,
                    $log->created_at?->toIso8601String(),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
