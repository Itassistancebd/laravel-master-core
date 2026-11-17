<?php

namespace MasterCore\Foundation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use MasterCore\Foundation\Contracts\CoreHealthInterface;

final class CoreHealthController
{
    public function __invoke(CoreHealthInterface $health): JsonResponse
    {
        abort_unless((bool) config('master-core.enable_health_endpoint', true), 404);

        return response()->json([
            'success' => true,
            'data' => $health->status(),
        ]);
    }
}
