<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\InstalledApp;
use App\Models\NotificationChannel;
use Illuminate\Support\Carbon;

/**
 * The UI is German, also where Carbon writes relative times ("vor 13 Minuten").
 */
class LocaleTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_german_is_the_default_when_the_container_sets_no_locale(): void
    {
        // the Core container does not set these: the config defaults have to be German
        $keys = ['APP_LOCALE', 'APP_FAKER_LOCALE'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        try {
            $config = require config_path('app.php');
        } finally {
            foreach ($saved as $key => [$env, $envArray, $server]) {
                if ($env !== false) {
                    putenv($key.'='.$env);
                }
                if ($envArray !== null) {
                    $_ENV[$key] = $envArray;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
            }
        }

        $this->assertSame('de', $config['locale']);
        $this->assertSame('de_DE', $config['faker_locale']);
        $this->assertSame('de', app()->getLocale());
        $this->assertSame('vor 13 Minuten', now()->subMinutes(13)->diffForHumans());
    }

    public function test_relative_times_are_german_on_the_pages(): void
    {
        $this->signIn();
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
        $app = InstalledApp::factory()->create([
            'last_seen_at' => now()->subMinutes(13),
            'update_checked_at' => now()->subHours(3),
        ]);
        $channel = ['type' => 'telegram', 'config' => ['bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUV', 'chat_id' => '42'], 'enabled' => true, 'min_severity' => 'warning'];
        // the page shows the newer of success and error per channel
        NotificationChannel::query()->create(['name' => 'Telegram', 'last_success_at' => now()->subSeconds(5)] + $channel);
        NotificationChannel::query()->create(['name' => 'Telegram 2', 'last_error_at' => now()->subDays(2), 'last_error' => 'Zeitüberschreitung'] + $channel);

        // dashboard: the last backup (Core panel)
        Backup::query()->create(['app_id' => $app->app_id, 'reason' => 'manual', 'status' => 'done'])
            ->forceFill(['created_at' => now()->subMinutes(13)])->save();
        $this->get('/')->assertOk()->assertSee('vor 13 Minuten')->assertDontSee(' ago');
        $this->get(route('apps.show', $app))->assertOk()->assertSee('Zuletzt geprüft: vor 3 Stunden')->assertDontSee(' ago');
        $this->get('/notifications')->assertOk()
            ->assertSee('Zuletzt zugestellt vor 5 Sekunden')->assertSee('Fehler vor 2 Tagen')->assertDontSee(' ago');

        // Uptime: incident duration without "vor" (diffForHumans with $syntax absolute)
        $this->assertSame('behoben nach 1 Stunde', 'behoben nach '.now()->addHour()->diffForHumans(now(), true));
    }
}
