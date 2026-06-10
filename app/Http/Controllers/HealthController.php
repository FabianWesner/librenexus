<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Report application and database health (FR-OPS-1).
     */
    public function __invoke(): JsonResponse
    {
        $databaseIsHealthy = $this->databaseIsHealthy();

        return response()->json([
            'status' => $databaseIsHealthy ? 'ok' : 'degraded',
            'database' => $databaseIsHealthy ? 'ok' : 'unreachable',
            'time' => now()->toIso8601String(),
        ], $databaseIsHealthy ? 200 : 503);
    }

    private function databaseIsHealthy(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
