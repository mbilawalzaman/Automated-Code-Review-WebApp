<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ReviewJobCancellationService;
use App\Models\ReviewJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ReviewJobController extends Controller
{
    public function __construct(
        protected ReviewJobCancellationService $cancellationService,
    ) {}

    public function cancel(Request $request, ReviewJob $reviewJob): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->cancellationService->cancel(
                $reviewJob,
                $request->user(),
                $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Review job #{$reviewJob->id} cancelled.");
    }
}
