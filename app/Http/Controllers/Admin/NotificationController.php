<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $query = Notification::query()
            ->with(['user:id,name,email', 'review:id,pull_request_title,commit_sha'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->string('channel')->toString());
        }

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $perPage = (int) $request->input('per_page', 25);

        return view('admin.notifications.index', [
            'notifications' => $query->paginate($perPage)->withQueryString(),
            'filters' => [
                'status' => $request->input('status'),
                'channel' => $request->input('channel'),
                'unread' => $request->boolean('unread'),
            ],
        ]);
    }

    public function markRead(Notification $notification): RedirectResponse
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return back()->with('status', 'Notification marked as read.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        Notification::query()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('status', 'All notifications marked as read.');
    }

    public function destroy(Notification $notification): RedirectResponse
    {
        $notification->delete();

        return back()->with('status', 'Notification deleted.');
    }
}
