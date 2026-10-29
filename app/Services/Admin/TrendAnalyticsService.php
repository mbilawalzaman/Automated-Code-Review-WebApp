<?php

namespace App\Services\Admin;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ReviewStatus;
use App\Models\Finding;
use App\Models\Review;
use Illuminate\Support\Carbon;

class TrendAnalyticsService
{
    /**
     * Build ApexCharts-ready series for dashboard trend widgets (UI-004).
     *
     * @return array<string, mixed>
     */
    public function dashboardTrends(int $days = 14): array
    {
        $days = max(7, min(90, $days));
        $start = Carbon::today()->subDays($days - 1);
        $labels = [];
        for ($i = 0; $i < $days; $i++) {
            $labels[] = $start->copy()->addDays($i)->format('Y-m-d');
        }

        return [
            'labels' => $labels,
            'security_findings' => $this->securityFindingsBySeverity($labels),
            'coverage' => $this->averageCoverage($labels),
            'failure_rates' => $this->reviewFailureRates($labels),
            'resolution' => $this->findingResolutionVelocity($labels),
        ];
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, list<int>>
     */
    protected function securityFindingsBySeverity(array $labels): array
    {
        $rows = Finding::query()
            ->selectRaw('DATE(created_at) as day, severity, COUNT(*) as total')
            ->whereDate('created_at', '>=', $labels[0])
            ->groupBy('day', 'severity')
            ->get();

        $series = [];
        foreach ([FindingSeverity::Critical, FindingSeverity::High, FindingSeverity::Medium, FindingSeverity::Low] as $severity) {
            $series[$severity->value] = array_fill(0, count($labels), 0);
        }

        $index = array_flip($labels);
        foreach ($rows as $row) {
            $day = (string) $row->day;
            $sev = $row->severity instanceof FindingSeverity
                ? $row->severity->value
                : (string) $row->severity;
            if (! isset($index[$day], $series[$sev])) {
                continue;
            }
            $series[$sev][$index[$day]] = (int) $row->total;
        }

        return $series;
    }

    /**
     * @param  list<string>  $labels
     * @return list<float|null>
     */
    protected function averageCoverage(array $labels): array
    {
        $rows = Review::query()
            ->selectRaw('DATE(completed_at) as day, AVG(COALESCE(changed_code_coverage, coverage_percentage)) as avg_coverage')
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', '>=', $labels[0])
            ->where(function ($q) {
                $q->whereNotNull('changed_code_coverage')
                    ->orWhereNotNull('coverage_percentage');
            })
            ->groupBy('day')
            ->pluck('avg_coverage', 'day');

        return array_map(function (string $day) use ($rows) {
            if (! isset($rows[$day])) {
                return null;
            }

            return round((float) $rows[$day], 1);
        }, $labels);
    }

    /**
     * @param  list<string>  $labels
     * @return array{failed: list<int>, total: list<int>, rate: list<float|null>}
     */
    protected function reviewFailureRates(array $labels): array
    {
        $rows = Review::query()
            ->selectRaw('DATE(completed_at) as day, status, COUNT(*) as total')
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', '>=', $labels[0])
            ->groupBy('day', 'status')
            ->get();

        $failed = array_fill(0, count($labels), 0);
        $total = array_fill(0, count($labels), 0);
        $index = array_flip($labels);

        foreach ($rows as $row) {
            $day = (string) $row->day;
            if (! isset($index[$day])) {
                continue;
            }
            $i = $index[$day];
            $status = $row->status instanceof ReviewStatus
                ? $row->status->value
                : (string) $row->status;
            $total[$i] += (int) $row->total;
            if (in_array($status, [ReviewStatus::Failed->value, ReviewStatus::ReviewError->value], true)) {
                $failed[$i] += (int) $row->total;
            }
        }

        $rate = [];
        foreach ($labels as $i => $_) {
            $rate[] = $total[$i] > 0 ? round(($failed[$i] / $total[$i]) * 100, 1) : null;
        }

        return compact('failed', 'total', 'rate');
    }

    /**
     * @param  list<string>  $labels
     * @return array{opened: list<int>, resolved: list<int>}
     */
    protected function findingResolutionVelocity(array $labels): array
    {
        $openedRows = Finding::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereDate('created_at', '>=', $labels[0])
            ->groupBy('day')
            ->pluck('total', 'day');

        $resolvedRows = Finding::query()
            ->selectRaw('DATE(COALESCE(resolved_at, updated_at)) as day, COUNT(*) as total')
            ->whereIn('status', [
                FindingStatus::Resolved->value,
                FindingStatus::FalsePositive->value,
                FindingStatus::Accepted->value,
            ])
            ->where(function ($q) use ($labels) {
                $q->whereDate('resolved_at', '>=', $labels[0])
                    ->orWhere(function ($inner) use ($labels) {
                        $inner->whereNull('resolved_at')->whereDate('updated_at', '>=', $labels[0]);
                    });
            })
            ->groupBy('day')
            ->pluck('total', 'day');

        $opened = [];
        $resolved = [];
        foreach ($labels as $day) {
            $opened[] = (int) ($openedRows[$day] ?? 0);
            $resolved[] = (int) ($resolvedRows[$day] ?? 0);
        }

        return compact('opened', 'resolved');
    }
}
