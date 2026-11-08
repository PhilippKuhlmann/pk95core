<?php

namespace App\Livewire\Notifications;

use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Models\NotificationChannel;
use App\Services\Notifications\Notifier;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Central notification channels: Telegram, e-mail, ntfy, webhook - each with a filter.
 */
#[Title('Benachrichtigungen')]
class Index extends Component
{
    /** null = list, 'new' or the channel id being edited */
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    private const CONFIG_KEYS = [
        'telegram' => ['bot_token', 'chat_id'],
        'email' => ['host', 'port', 'encryption', 'username', 'password', 'from', 'to'],
        'ntfy' => ['url', 'topic', 'token'],
        'webhook' => ['url'],
    ];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = self::blank();
        $this->editing = 'new';
    }

    public function edit(int $id): void
    {
        $this->resetErrorBag();
        $channel = NotificationChannel::query()->findOrFail($id);
        $config = $channel->config;
        foreach (NotificationChannel::SECRETS as $secret) {
            $config[$secret] = '';
        }
        $this->form = array_merge(self::blank(), $config, [
            'name' => $channel->name, 'type' => $channel->type, 'enabled' => $channel->enabled,
            'min_severity' => $channel->min_severity, 'apps' => $channel->apps ?? [], 'types' => implode(', ', $channel->types ?? []),
            'has_secret' => collect(NotificationChannel::SECRETS)->filter(fn ($s) => ! empty($channel->config[$s]))->values()->all(),
        ]);
        $this->editing = (string) $id;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $existing = $this->editing !== 'new' ? NotificationChannel::query()->findOrFail((int) $this->editing) : null;
        $keep = fn (string $key) => $existing !== null && ! empty($existing->config[$key]);
        $type = (string) ($this->form['type'] ?? '');

        $rules = [
            'form.name' => ['required', 'string', 'max:100'],
            'form.type' => ['required', Rule::in(array_keys(NotificationChannel::TYPES))],
            'form.min_severity' => ['required', Rule::in(array_keys(AppEvent::SEVERITIES))],
            'form.apps' => ['array'],
            'form.types' => ['nullable', 'string', 'max:500'],
        ] + match ($type) {
            'telegram' => [
                'form.bot_token' => [$keep('bot_token') ? 'nullable' : 'required', 'string', 'regex:/^\d+:[\w-]{20,}$/'],
                'form.chat_id' => ['required', 'string', 'max:100'],
            ],
            'email' => [
                'form.host' => ['required', 'string', 'max:255'],
                'form.port' => ['required', 'integer', 'between:1,65535'],
                'form.encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
                'form.username' => ['nullable', 'string', 'max:255'],
                'form.password' => ['nullable', 'string', 'max:500'],
                'form.from' => ['required', 'email'],
                'form.to' => ['required', 'email'],
            ],
            'ntfy' => [
                'form.url' => ['nullable', 'url:http,https', 'max:255'],
                'form.topic' => ['required', 'string', 'max:100', 'regex:/^[\w-]+$/'],
                'form.token' => ['nullable', 'string', 'max:255'],
            ],
            'webhook' => ['form.url' => ['required', 'url:http,https', 'max:500']],
            default => [],
        };
        $this->validate($rules, [
            'form.bot_token.regex' => 'So sieht ein Bot-Token aus: 123456:ABC-…',
            'form.topic.regex' => 'Nur Buchstaben, Ziffern, _ und -',
        ], [
            'form.name' => 'Name', 'form.bot_token' => 'Bot-Token', 'form.chat_id' => 'Chat-ID', 'form.host' => 'SMTP-Server',
            'form.port' => 'Port', 'form.from' => 'Absender', 'form.to' => 'Empfänger', 'form.url' => 'URL', 'form.topic' => 'Topic',
        ]);

        $config = [];
        foreach (self::CONFIG_KEYS[$type] as $key) {
            $value = $this->form[$key] ?? '';
            $config[$key] = in_array($key, NotificationChannel::SECRETS, true) && $value === '' && $keep($key)
                ? $existing->config[$key]
                : ($key === 'port' ? (int) $value : trim((string) $value));
        }

        $types = collect(explode(',', (string) ($this->form['types'] ?? '')))->map(fn ($t) => trim($t))->filter()->values()->all();
        $data = [
            'name' => trim((string) $this->form['name']), 'type' => $type, 'config' => $config,
            'enabled' => (bool) ($this->form['enabled'] ?? true), 'min_severity' => $this->form['min_severity'],
            'apps' => array_values((array) ($this->form['apps'] ?? [])) ?: null, 'types' => $types ?: null,
        ];
        $existing ? $existing->update($data) : NotificationChannel::query()->create($data);

        $this->dispatch('notice', kind: 'success', text: $existing ? 'Kanal gespeichert.' : 'Kanal angelegt.');
        $this->editing = null;
    }

    public function test(int $id, Notifier $notifier): void
    {
        $channel = NotificationChannel::query()->findOrFail($id);
        $event = new AppEvent([
            'app_id' => AppEvent::CORE, 'type' => 'core.test', 'severity' => 'info',
            'title' => 'Testnachricht', 'body' => "Der Kanal „{$channel->name}“ funktioniert.", 'occurred_at' => now(),
        ]);
        $error = $notifier->sendRecorded($channel, $event);
        $this->dispatch('notice', kind: $error ? 'error' : 'success', text: $error ?? 'Testnachricht gesendet.');
    }

    public function toggle(int $id): void
    {
        $channel = NotificationChannel::query()->findOrFail($id);
        $channel->update(['enabled' => ! $channel->enabled]);
    }

    public function delete(int $id): void
    {
        NotificationChannel::query()->findOrFail($id)->delete();
        $this->dispatch('notice', kind: 'success', text: 'Kanal gelöscht.');
    }

    public function render()
    {
        $apps = [AppEvent::CORE => 'PK95 Core'] + InstalledApp::query()->get()->mapWithKeys(fn (InstalledApp $a) => [$a->app_id => $a->displayName()])->all();

        return view('livewire.notifications.index', [
            'channels' => NotificationChannel::query()->orderBy('name')->get(),
            'apps' => $apps,
            'typeLabels' => NotificationChannel::TYPES,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function blank(): array
    {
        return [
            'name' => '', 'type' => 'telegram', 'enabled' => true, 'min_severity' => 'warning', 'apps' => [], 'types' => '',
            'bot_token' => '', 'chat_id' => '', 'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '',
            'password' => '', 'from' => '', 'to' => '', 'url' => '', 'topic' => '', 'token' => '', 'has_secret' => [],
        ];
    }
}
