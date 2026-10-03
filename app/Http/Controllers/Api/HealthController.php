<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = 'up';

        try {
            DB::select('SELECT 1');
        } catch (Throwable $e) {
            $database = 'down';
        }

        $healthy = $database === 'up';

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'services' => [
                'application' => 'up',
                'database' => $database,
            ],
            'timestamp' => now()->toISOString(),
        ], $healthy ? 200 : 503);
    }
}