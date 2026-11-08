<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppEvent;
use App\Models\InstalledApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/v1/events - an app reports an event (app protocol v1, section 4).
 * Authenticated with the app's own service token; the token decides which app it is.
 */
class EventController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $app = $this->appForToken((string) $request->bearerToken());
        if ($app === null) {
            return response()->json(['error' => 'Ungültiger Service-Token'], 401);
        }

        if (RateLimiter::tooManyAttempts('events:'.$app->app_id, 120)) {
            return response()->json(['error' => 'Zu viele Ereignisse'], 429);
        }
        RateLimiter::hit('events:'.$app->app_id, 60);

        $validator = Validator::make($request->json()->all(), [
            'type' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_.-]+$/'],
            'severity' => ['required', 'in:info,warning,critical'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'data' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'Ungültiges Ereignis', 'fields' => collect($validator->errors()->toArray())->map(fn ($m) => $m[0])], 422);
        }
        $input = $validator->validated();

        $occurredAt = isset($input['occurred_at']) ? Carbon::parse($input['occurred_at']) : now();
        // An app clock far off must not put events into the future.
        if ($occurredAt->isFuture()) {
            $occurredAt = now();
        }

        $event = AppEvent::record($app->app_id, $input['type'], $input['severity'], $input['title'], $input['body'] ?? null, $input['data'] ?? [], $occurredAt);

        return response()->json(['id' => $event->id], 202);
    }

    private function appForToken(string $token): ?InstalledApp
    {
        if (strlen($token) < 32) {
            return null;
        }
        // Tokens are stored encrypted; there are only a handful of apps.
        foreach (InstalledApp::query()->get() as $app) {
            if (hash_equals($app->token, $token)) {
                return $app;
            }
        }

        return null;
    }
}
