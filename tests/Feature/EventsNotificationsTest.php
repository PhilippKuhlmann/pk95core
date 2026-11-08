<?php

namespace Tests\Feature;

use App\Livewire\Notifications\Index as NotificationsPage;
use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Models\NotificationChannel;
use App\Services\Apps\AppManager;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

class EventsNotificationsTest extends FeatureTestCase
{
    /** @var list<array{url: string, body: string, headers: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org/*' => fn (Request $r) => $this->capture($r), 'ntfy.example/*' => fn (Request $r) => $this->capture($r),
            'hooks.example/*' => fn (Request $r) => $this->capture($r)]);
    }

    private function capture(Request $request)
    {
        $this->sent[] = ['url' => $request->url(), 'body' => $request->body(), 'headers' => $request->headers()];

        return str_contains($request->url(), 'fail') ? Http::response(['description' => 'Bad Request: chat not found'], 400) : Http::response(['ok' => true]);
    }

    private function demoApp(): InstalledApp
    {
        return InstalledApp::factory()->create();
    }

    private function channel(array $overrides = []): NotificationChannel
    {
        return NotificationChannel::query()->create($overrides + [
            'name' => 'Telegram', 'type' => 'telegram', 'config' => ['bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUV', 'chat_id' => '42'],
            'enabled' => true, 'min_severity' => 'warning',
        ]);
    }

    private function postEvent(string $token, array $body)
    {
        return $this->withToken($token)->postJson('/api/v1/events', $body);
    }

    public function test_apps_report_events_with_their_token(): void
    {
        $app = $this->demoApp();

        $this->postJson('/api/v1/events', ['type' => 'x', 'severity' => 'info', 'title' => 'x'])->assertStatus(401);
        $this->postEvent(str_repeat('a', 48), ['type' => 'x', 'severity' => 'info', 'title' => 'x'])->assertStatus(401);
        $this->postEvent($app->token, ['type' => 'Bad Type', 'severity' => 'loud', 'title' => ''])->assertStatus(422)->assertJsonStructure(['fields' => ['type', 'severity', 'title']]);

        $this->postEvent($app->token, [
            'type' => 'monitor.down', 'severity' => 'critical', 'title' => 'Webseite ist ausgefallen', 'body' => 'HTTP 502',
            'data' => ['monitorId' => 'm1'], 'occurred_at' => '2026-11-08T10:41:00Z',
        ])->assertStatus(202)->assertJsonStructure(['id']);

        $event = AppEvent::query()->sole();
        $this->assertSame('demo', $event->app_id);
        $this->assertSame('critical', $event->severity);
        $this->assertSame(['monitorId' => 'm1'], $event->data);
        $this->assertSame('2026-11-08 10:41:00', $event->occurred_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_events_go_to_matching_channels_only(): void
    {
        $app = $this->demoApp();
        $tg = $this->channel();
        $this->channel(['name' => 'Nur Uptime', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example/uptime'], 'apps' => ['uptime']]);
        $this->channel(['name' => 'Nur Updates', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example/updates'], 'types' => ['core.update.*'], 'min_severity' => 'info']);

        $this->postEvent($app->token, ['type' => 'item.created', 'severity' => 'info', 'title' => 'Unwichtig'])->assertStatus(202);
        $this->assertSame([], $this->sent);

        $this->postEvent($app->token, ['type' => 'monitor.down', 'severity' => 'critical', 'title' => 'Webseite ist ausgefallen', 'body' => 'HTTP 502'])->assertStatus(202);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('api.telegram.org/bot123456:ABCDEFGHIJKLMNOPQRSTUV/sendMessage', $this->sent[0]['url']);
        $payload = json_decode($this->sent[0]['body'], true);
        $this->assertSame('42', $payload['chat_id']);
        $this->assertSame("🔴 Demo-App: Webseite ist ausgefallen\nHTTP 502", $payload['text']);
        $this->assertNotNull($tg->refresh()->last_success_at);

        AppEvent::record(AppEvent::CORE, 'core.update.done', 'info', 'Uptime wurde aktualisiert');
        $this->assertSame('https://hooks.example/updates', end($this->sent)['url']);
        $this->assertSame('core.update.done', json_decode(end($this->sent)['body'], true)['type']);
    }

    public function test_ntfy_and_failures_are_recorded_without_secrets(): void
    {
        $ntfy = $this->channel(['name' => 'ntfy', 'type' => 'ntfy', 'config' => ['url' => 'https://ntfy.example', 'topic' => 'pk95', 'token' => 'tk_geheim123'], 'min_severity' => 'info']);
        $broken = $this->channel(['config' => ['bot_token' => '999:failfailfailfailfailfailfail', 'chat_id' => '1']]);

        AppEvent::record(AppEvent::CORE, 'core.app.failed', 'critical', 'Uptime ist gestört', 'Exit 1');

        $ntfyCall = collect($this->sent)->first(fn ($s) => str_contains($s['url'], 'ntfy.example'));
        $this->assertSame('https://ntfy.example/pk95', $ntfyCall['url']);
        $this->assertSame('Exit 1', $ntfyCall['body']);
        $this->assertSame(['5'], $ntfyCall['headers']['Priority']);
        $this->assertSame(['Bearer tk_geheim123'], $ntfyCall['headers']['Authorization']);

        $broken->refresh();
        $this->assertNotNull($broken->last_error_at);
        $this->assertStringContainsString('chat not found', $broken->last_error);
        $this->assertStringNotContainsString('failfailfail', $broken->last_error);
        $this->assertNotNull($ntfy->refresh()->last_success_at);
    }

    public function test_email_channel_uses_the_smtp_settings(): void
    {
        $mailer = Mail::build(['transport' => 'array']);
        $this->app->instance(Notifier::class, new class($mailer) extends Notifier
        {
            public array $configs = [];

            public function __construct(private Mailer $fake) {}

            protected function mailer(array $c): Mailer
            {
                $this->configs[] = $c;

                return $this->fake;
            }
        });
        $this->channel(['name' => 'Mail', 'type' => 'email', 'config' => ['host' => 'smtp.example', 'port' => 587, 'encryption' => 'tls',
            'username' => 'u', 'password' => 'p', 'from' => 'core@example.org', 'to' => 'admin@example.org']]);

        AppEvent::record(AppEvent::CORE, 'core.backup.failed', 'critical', 'Sicherung fehlgeschlagen', 'tar: disk full');

        $messages = $mailer->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertSame('[PK95] PK95 Core: Sicherung fehlgeschlagen', $email->getSubject());
        $this->assertSame('admin@example.org', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('tar: disk full', $email->getTextBody());
        $this->assertSame('smtp.example', app(Notifier::class)->configs[0]['host']);
    }

    public function test_core_reports_app_trouble_and_recovery(): void
    {
        $app = app(AppManager::class)->activate('demo')->refresh();

        $this->appHealthy = false;
        app(AppManager::class)->refresh($app);
        $this->appHealthy = true;
        app(AppManager::class)->refresh($app);
        $this->docker['containers']['cid-pk95-demo']['running'] = false;
        app(AppManager::class)->refresh($app);

        $this->assertSame(['core.app.unhealthy', 'core.app.recovered', 'core.app.stopped'], AppEvent::query()->orderBy('id')->pluck('type')->all());
    }

    public function test_channel_form_keeps_secrets_and_tests_the_channel(): void
    {
        $this->signIn();
        $channel = $this->channel();

        Livewire::test(NotificationsPage::class)
            ->call('edit', $channel->id)
            ->assertSet('form.bot_token', '')
            ->assertSee('Gespeichert – leer lassen')
            ->set('form.name', 'Telegram privat')
            ->set('form.types', 'monitor.*, core.*')
            ->call('save')
            ->assertHasNoErrors()
            ->call('test', $channel->id)
            ->assertDispatched('notice', kind: 'success');

        $channel->refresh();
        $this->assertSame('Telegram privat', $channel->name);
        $this->assertSame('123456:ABCDEFGHIJKLMNOPQRSTUV', $channel->config['bot_token']);
        $this->assertSame(['monitor.*', 'core.*'], $channel->types);
        $this->assertStringContainsString('Der Kanal „Telegram privat“ funktioniert.', json_decode(end($this->sent)['body'], true)['text']);

        Livewire::test(NotificationsPage::class)
            ->call('create')->set('form.type', 'telegram')->set('form.name', 'x')->set('form.bot_token', 'kaputt')->call('save')
            ->assertHasErrors(['form.bot_token', 'form.chat_id']);
    }

    public function test_pages_render(): void
    {
        $this->signIn();
        AppEvent::record(AppEvent::CORE, 'core.update.available', 'info', 'Für Demo-App gibt es Version 2.0.0');

        $this->get('/events')->assertOk()->assertSee('Für Demo-App gibt es Version 2.0.0')->assertSee('core.update.available');
        $this->get('/notifications')->assertOk()->assertSee('Noch kein Kanal');
        $this->get('/settings')->assertOk()->assertSee('Wartungsfenster');
        $this->get('/')->assertOk()->assertSee('Letzte Ereignisse');
    }
}
