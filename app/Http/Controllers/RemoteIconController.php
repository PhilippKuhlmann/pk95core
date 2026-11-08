<?php

namespace App\Http\Controllers;

use App\Models\InstalledApp;
use App\Services\Apps\AppException;
use Illuminate\Http\Response;

/**
 * Catalog icons of an app (GET /api/apps/{id}/icon at the app, which needs Core's token),
 * passed on to the browser. Only images; SVGs may not run scripts.
 */
class RemoteIconController extends Controller
{
    public function __invoke(InstalledApp $installedApp, string $icon): Response
    {
        abort_unless(preg_match('/^[a-z0-9-]{1,60}$/', $icon), 404);
        try {
            $remote = $installedApp->client()->get('/api/apps/'.$icon.'/icon');
        } catch (AppException) {
            abort(404);
        }
        $type = strtolower(strtok((string) $remote->header('Content-Type'), ';'));
        abort_unless($remote->successful() && in_array($type, ['image/svg+xml', 'image/png', 'image/jpeg', 'image/webp'], true), 404);

        return response($remote->body(), 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
