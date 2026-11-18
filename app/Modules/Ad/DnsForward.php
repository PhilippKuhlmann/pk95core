<?php

namespace App\Modules\Ad;

use App\Models\InstalledApp;
use App\Services\Apps\AppException;
use Illuminate\Support\Facades\Cache;

/**
 * The conditional forwarding in PK95 DNS that sends the AD realm to Samba's DNS
 * ({domain: <realm>, upstream: "127.0.0.1:<dns_port>"}). Added once, never changed: a rule the
 * user already has for the realm stays as it is.
 */
final class DnsForward
{
    public static function upstream(int $port): string
    {
        return '127.0.0.1:'.$port;
    }

    public static function dnsApp(): ?InstalledApp
    {
        return InstalledApp::query()->where('app_id', 'dns')->where('status', InstalledApp::RUNNING)->first();
    }

    /**
     * state: present | other (a rule for the realm with another upstream) | missing | no_dns | error
     *
     * @return array{state: string, upstream: string, current: ?string, error: ?string}
     */
    public static function state(string $realm, int $port): array
    {
        $result = ['state' => 'no_dns', 'upstream' => self::upstream($port), 'current' => null, 'error' => null];
        $dns = self::dnsApp();
        if ($dns === null) {
            return $result;
        }
        try {
            $response = $dns->client()->get('/api/settings');
        } catch (AppException $e) {
            return ['state' => 'error', 'error' => $e->getMessage()] + $result;
        }
        if (! $response->successful()) {
            return ['state' => 'error', 'error' => (string) ($response->json('error') ?? "PK95 DNS antwortet mit HTTP {$response->status()}.")] + $result;
        }
        foreach ((array) $response->json('conditional') as $rule) {
            if (self::same((string) ($rule['domain'] ?? ''), $realm)) {
                $current = (string) ($rule['upstream'] ?? '');

                return ['state' => $current === $result['upstream'] ? 'present' : 'other', 'current' => $current] + $result;
            }
        }

        return ['state' => 'missing'] + $result;
    }

    /**
     * Add the rule when PK95 DNS runs and has none for the realm yet.
     *
     * @return array{state: string, upstream: string, current: ?string, error: ?string} the state afterwards (created: "present")
     */
    public static function ensure(string $realm, int $port): array
    {
        $state = self::state($realm, $port);
        if ($state['state'] !== 'missing') {
            return $state;
        }
        $dns = self::dnsApp();
        try {
            $rules = array_values((array) $dns?->client()->get('/api/settings')->json('conditional'));
            $rules[] = ['domain' => $realm, 'upstream' => $state['upstream']];
            $response = $dns?->client()->put('/api/settings', ['conditional' => $rules]);
        } catch (AppException $e) {
            return ['state' => 'error', 'error' => $e->getMessage()] + $state;
        }
        if ($response === null || ! $response->successful()) {
            $fields = (array) $response?->json('fields');

            return ['state' => 'error', 'error' => (string) (reset($fields) ?: ($response?->json('error') ?? 'PK95 DNS hat die Weiterleitung abgelehnt.'))] + $state;
        }

        return ['state' => 'present', 'current' => $state['upstream']] + $state;
    }

    /**
     * The rule Core keeps for PK95 AD: realm and upstream of the running AD app; null without
     * one, before the setup or when it does not answer. Cached a minute, the DNS records page
     * asks on every render.
     *
     * @return array{domain: string, upstream: string}|null
     */
    public static function adRule(): ?array
    {
        $ad = InstalledApp::query()->where('app_id', 'ad')->where('status', InstalledApp::RUNNING)->first();
        if ($ad === null) {
            return null;
        }

        return Cache::remember('pk95.ad.forward-rule.'.$ad->id, 60, function () use ($ad) {
            try {
                $response = $ad->client()->get('/api/domain');
            } catch (AppException) {
                return null;
            }
            $realm = strtolower(trim((string) $response->json('realm')));
            if (! $response->successful() || $realm === '') {
                return null;
            }

            return ['domain' => $realm, 'upstream' => self::upstream((int) ($response->json('dns_port') ?? 5300))];
        });
    }

    /**
     * A conditional rule of PK95 DNS is the one Core created for PK95 AD (same realm, upstream
     * 127.0.0.1:<dns_port>).
     *
     * @param  array<string, mixed>  $rule  {domain, upstream}
     * @param  array{domain: string, upstream: string}|null  $ad  from adRule()
     */
    public static function isAdRule(array $rule, ?array $ad): bool
    {
        return $ad !== null && self::same((string) ($rule['domain'] ?? ''), $ad['domain']) && trim((string) ($rule['upstream'] ?? '')) === $ad['upstream'];
    }

    private static function same(string $a, string $b): bool
    {
        return strtolower(rtrim(trim($a), '.')) === strtolower(rtrim(trim($b), '.'));
    }
}
