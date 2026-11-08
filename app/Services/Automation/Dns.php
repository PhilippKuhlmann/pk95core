<?php

namespace App\Services\Automation;

use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Does a domain point to this installation? Checked from the outside: the public IP and
 * the public DNS answer (DNS over HTTPS). The local resolver would answer with internal
 * rewrites (AdGuard) and say yes although Let's Encrypt cannot reach the server.
 */
class Dns
{
    public const DOH = 'https://cloudflare-dns.com/dns-query';

    public const TRACE = 'https://1.1.1.1/cdn-cgi/trace';

    /** Public IPv4 of the internet connection: setting, else asked from the outside (cached). */
    public function publicIp(): ?string
    {
        $fixed = trim((string) Settings::get('automation_public_ip'));
        if ($fixed !== '') {
            return $fixed;
        }

        return Cache::remember('automation.public_ip', 600, function () {
            try {
                $body = Http::timeout(5)->get(self::TRACE)->body();

                return preg_match('/^ip=([0-9.]+)$/m', $body, $m) ? $m[1] : null;
            } catch (Throwable) {
                return null;
            }
        });
    }

    /**
     * IPv4 addresses of the domain in public DNS (CNAMEs are resolved by the resolver).
     *
     * @return list<string>|null null when the lookup itself failed
     */
    public function resolve(string $domain): ?array
    {
        try {
            $response = Http::timeout(5)->withHeaders(['Accept' => 'application/dns-json'])
                ->get(self::DOH, ['name' => $domain, 'type' => 'A']);
            if (! $response->successful()) {
                return null;
            }

            return array_values(array_map(fn ($a) => (string) $a['data'],
                array_filter((array) $response->json('Answer'), fn ($a) => ($a['type'] ?? 0) === 1)));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{ok: bool, ip: ?string, found: list<string>, reason: string}
     */
    public function check(string $domain): array
    {
        $ip = $this->publicIp();
        $found = $this->resolve($domain);
        if ($ip === null) {
            return ['ok' => false, 'ip' => null, 'found' => $found ?? [], 'reason' => 'Die öffentliche IP ist nicht bekannt. Trag sie unter Automatik ein.'];
        }
        if ($found === null) {
            return ['ok' => false, 'ip' => $ip, 'found' => [], 'reason' => 'Die DNS-Abfrage ist fehlgeschlagen.'];
        }
        if (! in_array($ip, $found, true)) {
            return ['ok' => false, 'ip' => $ip, 'found' => $found, 'reason' => $found
                ? "{$domain} zeigt auf ".implode(', ', $found).", nicht auf {$ip}."
                : "{$domain} hat noch keinen DNS-Eintrag. Lege einen A-Eintrag auf {$ip} an."];
        }

        return ['ok' => true, 'ip' => $ip, 'found' => $found, 'reason' => ''];
    }
}
