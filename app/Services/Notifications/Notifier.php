<?php

namespace App\Services\Notifications;

use App\Models\AppEvent;
use App\Models\NotificationChannel;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sends one event to one channel. Throws a RuntimeException with a readable message
 * (no secrets) when the channel refuses it.
 */
class Notifier
{
    private const ICONS = ['info' => 'ℹ️', 'warning' => '🟠', 'critical' => '🔴'];

    /**
     * Deliver to every matching channel and record the outcome on the channel.
     */
    public function deliver(AppEvent $event): void
    {
        foreach (NotificationChannel::query()->where('enabled', true)->get() as $channel) {
            if (! $channel->matches($event)) {
                continue;
            }
            $this->sendRecorded($channel, $event);
        }
    }

    /**
     * Send and keep the result on the channel; returns the error message or null.
     */
    public function sendRecorded(NotificationChannel $channel, AppEvent $event): ?string
    {
        try {
            $this->send($channel, $event);
            $channel->forceFill(['last_success_at' => now()])->save();

            return null;
        } catch (\Throwable $e) {
            $message = $this->scrub($e->getMessage(), $channel);
            $channel->forceFill(['last_error_at' => now(), 'last_error' => mb_substr($message, 0, 500)])->save();

            return $message;
        }
    }

    public function send(NotificationChannel $channel, AppEvent $event): void
    {
        $c = $channel->config;
        $app = $event->appName();
        $line = (self::ICONS[$event->severity] ?? '').' '.$app.': '.$event->title;
        $text = trim($line."\n".($event->body ?? ''));

        match ($channel->type) {
            'telegram' => $this->check(Http::timeout(15)
                ->post('https://api.telegram.org/bot'.$c['bot_token'].'/sendMessage', ['chat_id' => $c['chat_id'], 'text' => $text, 'disable_web_page_preview' => true]), 'Telegram'),
            'ntfy' => $this->check(Http::timeout(15)
                ->withHeaders(array_filter([
                    'Title' => $this->header($app.': '.$event->title),
                    'Priority' => ['info' => '3', 'warning' => '4', 'critical' => '5'][$event->severity] ?? '3',
                    'Tags' => $event->severity === 'critical' ? 'rotating_light' : ($event->severity === 'warning' ? 'warning' : 'information_source'),
                    'Authorization' => ! empty($c['token']) ? 'Bearer '.$c['token'] : null,
                ]))
                ->withBody($event->body ?: $event->title, 'text/plain')
                ->post(rtrim($c['url'] ?: 'https://ntfy.sh', '/').'/'.rawurlencode($c['topic'])), 'ntfy'),
            'webhook' => $this->check(Http::timeout(15)->post($c['url'], [
                'app' => $event->app_id,
                'app_name' => $app,
                'type' => $event->type,
                'severity' => $event->severity,
                'title' => $event->title,
                'body' => $event->body,
                'data' => $event->data,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ]), 'Webhook'),
            'email' => $this->mailer($c)->raw($text, function ($message) use ($c, $app, $event) {
                $message->from($c['from'])->to($c['to'])->subject('[PK95] '.$app.': '.$event->title);
            }),
            default => throw new RuntimeException("Unbekannter Kanaltyp {$channel->type}"),
        };
    }

    /**
     * A mailer for the channel's SMTP server. Tests replace this.
     *
     * @param  array<string, mixed>  $c
     */
    protected function mailer(array $c): Mailer
    {
        return Mail::build([
            'transport' => 'smtp',
            'scheme' => ($c['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'host' => $c['host'],
            'port' => (int) $c['port'],
            'username' => $c['username'] ?: null,
            'password' => $c['password'] ?: null,
            'timeout' => 15,
        ]);
    }

    private function check(Response $response, string $what): void
    {
        if (! $response->successful()) {
            $detail = $response->json('description') ?? $response->json('error') ?? mb_substr(trim($response->body()), 0, 200);
            throw new RuntimeException("{$what} antwortet mit HTTP {$response->status()}".($detail ? ": {$detail}" : ''));
        }
    }

    /** Header values must be one line of Latin-1 for some proxies; ntfy accepts UTF-8 via RFC 2047. */
    private function header(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        return mb_check_encoding($value, 'ASCII') ? $value : '=?UTF-8?B?'.base64_encode($value).'?=';
    }

    /** No secret may end up in an error message shown in the UI. */
    private function scrub(string $message, NotificationChannel $channel): string
    {
        foreach (NotificationChannel::SECRETS as $key) {
            $secret = (string) ($channel->config[$key] ?? '');
            if (strlen($secret) >= 4) {
                $message = str_replace($secret, '***', $message);
            }
        }

        return $message;
    }
}
