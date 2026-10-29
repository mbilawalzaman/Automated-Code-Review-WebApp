<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Http\Controllers\Controller;
use App\Models\WorkerHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerHeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'worker_id' => 'required|string|max:64',
            'hostname' => 'nullable|string|max:128',
            'os_info' => 'nullable|string|max:128',
            'python_version' => 'nullable|string|max:128',
            'payload' => 'nullable|array',
        ]);

        $workerId = trim($validated['worker_id']);
        $hostname = isset($validated['hostname']) ? trim($validated['hostname']) : null;
        $osInfo = isset($validated['os_info']) ? trim($validated['os_info']) : null;
        $pythonVersion = isset($validated['python_version']) ? trim($validated['python_version']) : null;

        $heartbeat = WorkerHeartbeat::query()->updateOrCreate(
            ['worker_id' => $workerId],
            [
                'hostname' => $hostname,
                'os_info' => $osInfo,
                'python_version' => $pythonVersion,
                'last_ping_at' => now(),
                'payload' => $validated['payload'] ?? null,
                'is_currently_offline' => false,
                'current_outage_episode_id' => null,
            ]
        );

        return response()->json([
            'success' => true,
            'worker_id' => $heartbeat->worker_id,
            'last_ping_at' => $heartbeat->last_ping_at->toIso8601String(),
        ]);
    }
}
