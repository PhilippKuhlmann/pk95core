<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstalledApp;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/context - settings an app needs from Core (app token like the events API).
 * ProxApps builds the domain of a deployment from the base domain.
 */
class ContextController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (EventController::appForToken((string) $request->bearerToken()) === null) {
            return response()->json(['error' => 'Ungültiger Service-Token'], 401);
        }
        $running = fn (string $id) => InstalledApp::query()->where('app_id', $id)->where('status', InstalledApp::RUNNING)->exists();

        return response()->json([
            'base_domain' => (string) Settings::get('automation_base_domain'),
            'proxy' => (bool) Settings::get('automation_proxy') && $running('proxy'),
            'uptime' => (bool) Settings::get('automation_uptime') && $running('uptime'),
        ]);
    }
}
