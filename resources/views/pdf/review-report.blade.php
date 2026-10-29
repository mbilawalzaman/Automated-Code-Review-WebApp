<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Automated Code Review Summary — #{{ $review->id }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            margin: 0;
            padding: 24px;
            background-color: #ffffff;
            font-size: 12px;
            line-height: 1.45;
        }
        .header {
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 16px;
            margin-bottom: 24px;
            width: 100%;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
        }
        .brand {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
        }
        .sub-brand {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }
        .report-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-passed { background-color: #dcfce7; color: #166534; }
        .badge-passed_with_warnings { background-color: #fef9c3; color: #854d0e; }
        .badge-failed { background-color: #fee2e2; color: #991b1b; }
        .badge-review_error { background-color: #f3f4f6; color: #374151; }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            table-layout: fixed;
        }
        .meta-table td {
            width: 25%;
            padding: 8px 10px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .col-label {
            font-size: 9px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .col-value {
            font-weight: 600;
            color: #0f172a;
            font-size: 11px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 20px;
            margin-bottom: 10px;
            border-left: 4px solid #3b82f6;
            padding-left: 8px;
        }

        .summary-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 14px;
            margin-bottom: 20px;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }

        .tools-list {
            margin-top: 8px;
        }
        .tool-tag {
            display: inline-block;
            background-color: #e2e8f0;
            color: #334155;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 500;
            margin-right: 4px;
            margin-bottom: 4px;
        }

        .finding {
            border: 1px solid #e2e8f0;
            margin-bottom: 10px;
            page-break-inside: avoid;
        }
        .finding-header {
            background-color: #f1f5f9;
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .finding-body {
            padding: 8px 10px;
            word-wrap: break-word;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .finding-meta {
            font-size: 10px;
            color: #64748b;
            margin-top: 4px;
        }
        .finding-path {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 10px;
            color: #0f172a;
            word-break: break-all;
            overflow-wrap: anywhere;
        }
        .finding-block {
            margin-top: 6px;
            font-size: 11px;
        }
        .finding-fix {
            margin-top: 6px;
            font-size: 11px;
            color: #15803d;
        }

        .sev-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            margin-right: 6px;
            vertical-align: middle;
        }
        .sev-critical { background-color: #7f1d1d; color: #ffffff; }
        .sev-high { background-color: #fee2e2; color: #991b1b; }
        .sev-medium { background-color: #fef9c3; color: #854d0e; }
        .sev-low { background-color: #e0f2fe; color: #075985; }

        .footer {
            margin-top: 32px;
            border-top: 1px solid #e2e8f0;
            padding-top: 12px;
            text-align: center;
            font-size: 10px;
            color: #94a3b8;
        }
    </style>
</head>
<body>

    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 70%; vertical-align: top;">
                    <div class="brand">Automated Code Review Report</div>
                    <div class="sub-brand">Enterprise QA &amp; Security Audit Infrastructure</div>
                </td>
                <td style="width: 30%; text-align: right; vertical-align: top;">
                    <span class="report-badge badge-{{ strtolower($review->status->value ?? $review->status) }}">
                        STATUS: {{ strtoupper(str_replace('_', ' ', $review->status->value ?? $review->status)) }}
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <table class="meta-table">
        <tr>
            <td>
                <div class="col-label">Repository</div>
                <div class="col-value">{{ $review->repository?->full_name ?? 'N/A' }}</div>
            </td>
            <td>
                <div class="col-label">Commit SHA</div>
                <div class="col-value">{{ substr($review->commit_sha ?? 'HEAD', 0, 10) }}</div>
            </td>
            <td>
                <div class="col-label">Author</div>
                <div class="col-value">{{ $review->author ?? 'Developer' }}</div>
            </td>
            <td>
                <div class="col-label">Review Date</div>
                <div class="col-value">{{ $review->created_at?->format('M d, Y H:i UTC') ?? now()->format('M d, Y H:i UTC') }}</div>
            </td>
        </tr>
    </table>

    <div class="section-title">Executive Summary</div>
    <div class="summary-box">
        <div>{{ $review->summary ?? 'Automated review pipeline completed.' }}</div>
        @if(!empty($review->tools_used))
            <div class="tools-list">
                <strong>Static &amp; AI Scanners Used:</strong>
                @foreach((array)$review->tools_used as $tool)
                    <span class="tool-tag">{{ $tool }}</span>
                @endforeach
            </div>
        @endif
    </div>

    <div class="section-title">Security &amp; Quality Findings ({{ count($review->findings) }})</div>
    @if(count($review->findings) > 0)
        @php
            $redactor = app(\App\Services\Api\RedactionService::class);
        @endphp
        @foreach($review->findings as $finding)
            <div class="finding">
                <div class="finding-header">
                    <span class="sev-badge sev-{{ strtolower($finding->severity->value ?? $finding->severity) }}">
                        {{ strtoupper($finding->severity->value ?? $finding->severity) }}
                    </span>
                    <strong>{{ $finding->title }}</strong>
                    <div class="finding-meta">Rule: {{ $finding->rule_id ?? 'N/A' }}</div>
                </div>
                <div class="finding-body">
                    <div class="finding-path">{{ $finding->file_path ?: 'Unknown' }}</div>
                    <div class="finding-meta">Line: {{ $finding->line_number ?? 'N/A' }}</div>
                    @if($finding->evidence)
                        <div class="finding-block"><strong>Evidence:</strong> {{ $redactor->redact($finding->evidence) }}</div>
                    @endif
                    @if($finding->recommendation)
                        <div class="finding-fix"><strong>Fix:</strong> {{ $redactor->redact($finding->recommendation) }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    @else
        <div class="summary-box" style="color: #166534; background-color: #f0fdf4; border-color: #bbf7d0;">
            No security, vulnerability, or code quality findings were detected during this review.
        </div>
    @endif

    <div class="footer">
        Generated automatically by Automated Code Review Control Plane &bull; Immutable Audit Evidence
    </div>

</body>
</html>
