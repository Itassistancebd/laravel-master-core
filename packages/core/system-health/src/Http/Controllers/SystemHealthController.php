<?php

namespace MasterCore\SystemHealth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use MasterCore\SystemHealth\Support\HealthCheckRegistry;

final class SystemHealthController
{
    public function __construct(private readonly HealthCheckRegistry $checks) {}

    public function __invoke(): JsonResponse
    {
        $results = $this->checks->run();

        return response()->json([
            'success' => true,
            'data' => [
                'checked_at' => Carbon::now()->toIso8601String(),
                'overall_status' => $this->checks->overallStatus($results),
                'scope' => 'Only the listed request and primary database checks are reported.',
                'checks' => $results,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
