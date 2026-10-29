<x-mail::message>
# Critical review alert

A review completed with **Critical** findings and requires attention.

**Repository:** {{ $review->repository?->full_name ?? 'N/A' }}  
**Status:** {{ is_object($review->status) ? $review->status->label() : $review->status }}  
**Commit:** `{{ \Illuminate\Support\Str::limit($review->commit_sha ?? 'N/A', 12, '') }}`  
@if($review->pull_request_number)
**Pull request:** #{{ $review->pull_request_number }}  
@endif
**Author:** {{ $review->author ?? 'N/A' }}  
**Critical findings:** {{ count($criticalFindings) }}

---

@foreach($criticalFindings as $finding)
- **{{ $finding['title'] }}** ({{ $finding['severity'] }})  
  `{{ $finding['file_path'] }}`@if(!empty($finding['line_number'])) :{{ $finding['line_number'] }}@endif  
  @if(!empty($finding['evidence']))
  {{ $finding['evidence'] }}
  @endif

@endforeach

<x-mail::button :url="$dashboardUrl">
Open dashboard
</x-mail::button>

This message is redacted and must not contain secrets or credentials (NOT-002).

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
