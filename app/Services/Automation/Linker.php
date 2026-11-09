<?php

namespace App\Services\Automation;

use App\Models\AppEvent;
use App\Models\AppLink;
use App\Models\InstalledApp;
use App\Modules\Proxy\Hosts;
use App\Services\Apps\AppException;
use App\Support\Settings;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Links a deployed app with the other apps: a public proxy host in PK95 Proxy (enabled
 * once the domain points to the public IP), a local DNS record in PK95 DNS (same domain →
 * LAN IP of the proxy, so the LAN reaches the app without a detour over the router) and
 * two monitors in PK95 Uptime (the internal target and the domain). Removing the
 * deployment removes all of it again. Each part fails on its own: a missing or unreachable
 * app only leaves a note, and the DNS record can be caught up later (syncDns).
 */
class Linker
{
    public const GROUP = 'Apps';

    public function __construct(private readonly Dns $dns, private readonly LanIp $lanIp) {}

    /**
     * deployment.succeeded of ProxApps.
     *
     * @param  array<string, mixed>  $d  event data (deploymentId, appName, hostname, ip, domain, proxyUrl)
     */
    public function link(string $sourceApp, array $d): ?AppLink
    {
        $target = trim((string) ($d['proxyUrl'] ?? ''));
        $id = (int) ($d['deploymentId'] ?? 0);
        if ($target === '' || $id <= 0 || ! self::parseTarget($target)) {
            return null;
        }
        $link = AppLink::query()->firstOrNew(['source_app' => $sourceApp, 'source_id' => $id]);
        $link->fill([
            'name' => (string) ($d['appName'] ?? $sourceApp),
            'hostname' => (string) ($d['hostname'] ?? ''),
            'domain' => strtolower(trim((string) ($d['domain'] ?? ''))),
            'target' => $target,
            'monitor_ids' => $link->monitor_ids ?? [],
            'checked_at' => now(),
        ]);
        $notes = [];
        $enabled = false;

        $wanted = ($d['proxy'] ?? true) !== false;
        $proxy = $wanted ? $this->app('proxy', 'automation_proxy') : null;
        if (! $wanted) {
            $notes[] = 'Proxy-Eintrag beim Ausrollen abgewählt.';
        } elseif ($proxy && $link->domain !== '' && ! $link->proxy_host_id) {
            try {
                $dns = $this->dns->check($link->domain);
                $enabled = $dns['ok'];
                $link->proxy_host_id = $this->createHost($proxy, $link, $enabled, $notes);
                if ($link->proxy_host_id && ! $enabled) {
                    $notes[] = 'Host deaktiviert angelegt: '.$dns['reason'].' Core schaltet ihn ein, sobald DNS passt.';
                }
            } catch (Throwable $e) {
                $notes[] = 'Proxy-Host nicht angelegt: '.$e->getMessage();
            }
        } elseif ($link->domain === '') {
            $notes[] = 'Keine Domain angegeben: kein Proxy-Host.';
        }

        $this->ensureRecord($link);

        $uptime = $this->app('uptime', 'automation_uptime');
        if ($uptime && ! $link->monitor_ids) {
            try {
                $ids = [$this->createMonitor($uptime, self::internalMonitor($link))];
                if ($enabled) {
                    $ids[] = $this->createMonitor($uptime, self::domainMonitor($link));
                }
                $link->monitor_ids = $ids;
            } catch (Throwable $e) {
                $notes[] = 'Monitore nicht angelegt: '.$e->getMessage();
            }
        }

        $link->state = $link->proxy_host_id && ! $enabled ? AppLink::PENDING_DNS
            : ($link->proxy_host_id || $link->monitor_ids ? AppLink::ACTIVE : AppLink::FAILED);
        $link->message = $notes ? implode(' ', $notes) : null;
        $link->save();

        $details = trim(implode(' ', array_filter([$link->message, $link->dns_message])));
        $title = match ($link->state) {
            AppLink::ACTIVE => "{$link->name} ist unter {$this->where($link)} eingerichtet",
            AppLink::PENDING_DNS => "{$link->name}: {$link->domain} wartet auf DNS",
            default => "{$link->name} konnte nicht automatisch eingerichtet werden",
        };
        AppEvent::record(AppEvent::CORE, 'core.link.'.$link->state, $link->state === AppLink::ACTIVE && $link->dns_message === null ? 'info' : 'warning',
            $title, $details !== '' ? $details : null, ['link' => $link->id, 'domain' => $link->domain]);

        return $link;
    }

    /**
     * Hosts waiting for DNS: enable them (and add the domain monitor) once the domain points here.
     */
    public function recheck(AppLink $link): void
    {
        if ($link->state !== AppLink::PENDING_DNS || ! $link->proxy_host_id) {
            return;
        }
        $proxy = $this->app('proxy');
        if (! $proxy) {
            return;
        }
        $dns = $this->dns->check($link->domain);
        $link->checked_at = now();
        if (! $dns['ok']) {
            if ($link->created_at->lt(now()->subDays(AppLink::DNS_WAIT_DAYS))) {
                $link->update(['state' => AppLink::FAILED, 'message' => 'Nach '.AppLink::DNS_WAIT_DAYS.' Tagen zeigt die Domain noch nicht hierher: '.$dns['reason']]);
                AppEvent::record(AppEvent::CORE, 'core.link.failed', 'warning', "{$link->name}: {$link->domain} zeigt nicht hierher", $link->message, ['link' => $link->id]);
            } else {
                $link->update(['message' => 'Host deaktiviert: '.$dns['reason'].' Core schaltet ihn ein, sobald DNS passt.']);
            }

            return;
        }

        try {
            $host = collect((array) $proxy->client()->get('/api/hosts')->json())->firstWhere('id', $link->proxy_host_id);
            if ($host === null) {
                $link->update(['state' => AppLink::FAILED, 'message' => 'Der Proxy-Host wurde inzwischen gelöscht.']);

                return;
            }
            $body = array_merge(array_intersect_key($host, array_flip(Hosts::FIELDS)), ['enabled' => true]);
            $response = $proxy->client()->put('/api/hosts/'.$link->proxy_host_id, $body);
            if (! $response->successful()) {
                throw new AppException((string) ($response->json('error') ?? 'HTTP '.$response->status()));
            }
            $ids = $link->monitor_ids ?? [];
            if (($uptime = $this->app('uptime', 'automation_uptime')) && count($ids) < 2) {
                $ids[] = $this->createMonitor($uptime, self::domainMonitor($link));
            }
            $link->update(['state' => AppLink::ACTIVE, 'monitor_ids' => $ids, 'message' => null]);
            AppEvent::record(AppEvent::CORE, 'core.link.active', 'info', "{$link->name} ist unter https://{$link->domain} erreichbar",
                'DNS zeigt jetzt hierher, der Proxy-Host ist eingeschaltet.', ['link' => $link->id, 'domain' => $link->domain]);
        } catch (Throwable $e) {
            $link->update(['message' => 'Einschalten fehlgeschlagen: '.$e->getMessage()]);
        }
    }

    /**
     * deployment.removed: delete what Core created for the deployment.
     */
    public function unlink(string $sourceApp, int $sourceId): ?AppLink
    {
        $link = AppLink::query()->where('source_app', $sourceApp)->where('source_id', $sourceId)->first();
        if ($link === null || $link->state === AppLink::REMOVED) {
            return $link;
        }
        $notes = [];
        if ($link->proxy_host_id && ($proxy = $this->app('proxy'))) {
            $notes[] = $this->delete($proxy, '/api/hosts/'.$link->proxy_host_id, 'Proxy-Host');
        }
        if ($link->monitor_ids && ($uptime = $this->app('uptime'))) {
            foreach ($link->monitor_ids as $monitor) {
                $notes[] = $this->delete($uptime, '/api/monitors/'.rawurlencode($monitor), 'Monitor');
            }
        }
        if ($link->dns_record_id) {
            $dnsApp = $this->app('dns');
            $notes[] = $dnsApp ? $this->delete($dnsApp, '/api/rewrites/'.$link->dns_record_id, 'DNS-Eintrag')
                : "DNS-Eintrag {$link->domain} nicht gelöscht: PK95 DNS läuft nicht. Lösche ihn dort von Hand.";
        }
        $link->update(['state' => AppLink::REMOVED, 'proxy_host_id' => null, 'monitor_ids' => [],
            'dns_record_id' => null, 'dns_answer' => '', 'dns_message' => null,
            'message' => implode(' ', array_filter($notes)) ?: null]);
        AppEvent::record(AppEvent::CORE, 'core.link.removed', $link->message ? 'warning' : 'info',
            "{$link->name}: Proxy-Host, DNS-Eintrag und Monitore entfernt", $link->message, ['link' => $link->id]);

        return $link;
    }

    /**
     * Catch up the local DNS records of deployed apps: create missing ones (DNS was missing,
     * unreachable or installed later) and point Core's records to a changed LAN IP. Only
     * links with a proxy host count; records Core did not create are never touched.
     *
     * @return array{changed: int, open: list<string>} open = domains still without a record
     */
    public function syncDns(): array
    {
        $out = ['changed' => 0, 'open' => []];
        $links = AppLink::query()->where('state', '!=', AppLink::REMOVED)->whereNotNull('proxy_host_id')->where('domain', '!=', '')->get();
        foreach ($links as $link) {
            $changed = $this->ensureRecord($link);
            $link->save();
            if ($changed) {
                $out['changed']++;
                AppEvent::record(AppEvent::CORE, 'core.link.dns', 'info', "{$link->name}: lokaler DNS-Eintrag {$link->domain} → {$link->dns_answer}",
                    null, ['link' => $link->id, 'domain' => $link->domain]);
            }
            if ($link->dns_message !== null) {
                $out['open'][] = $link->domain;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------

    /**
     * The local DNS record of a link: created when missing, moved to the current LAN IP when
     * that changed. Problems end up in dns_message, never as an exception. The caller saves.
     *
     * @return bool something changed in PK95 DNS
     */
    private function ensureRecord(AppLink $link): bool
    {
        if ($link->domain === '' || ! $link->proxy_host_id || ! Settings::get('automation_dns')) {
            return false;
        }
        $dnsApp = $this->app('dns');
        if ($dnsApp === null) {
            $link->dns_message = InstalledApp::query()->where('app_id', 'dns')->exists()
                ? 'PK95 DNS läuft gerade nicht: noch kein lokaler DNS-Eintrag. Core legt ihn an, sobald DNS wieder läuft.'
                : 'PK95 DNS ist nicht installiert: kein lokaler DNS-Eintrag. Installierst du DNS später, legt Core ihn an.';

            return false;
        }
        $ip = $this->lanIp->current();
        if ($ip === null) {
            $link->dns_message = 'Die LAN-IP des Proxy ist nicht bekannt: kein lokaler DNS-Eintrag. Trag sie unter Automatik ein.';

            return false;
        }
        $body = ['domain' => $link->domain, 'answer' => $ip, 'enabled' => true];
        try {
            $client = $dnsApp->client();
            if ($link->dns_record_id) {
                if ($link->dns_answer === $ip) {
                    $link->dns_message = null;

                    return false;
                }
                $response = $client->put('/api/rewrites/'.$link->dns_record_id, $body);
                if ($response->status() !== 404) {
                    self::ensureOk($response);
                    $link->fill(['dns_answer' => $ip, 'dns_message' => null]);

                    return true;
                }
                $link->dns_record_id = null; // deleted in PK95 DNS in the meantime: create it again
            }
            $existing = collect((array) $client->get('/api/rewrites')->json())
                ->first(fn ($r) => strtolower((string) ($r['domain'] ?? '')) === $link->domain);
            if ($existing !== null) {
                $link->fill(['dns_answer' => '', 'dns_message' => "Für {$link->domain} gibt es in PK95 DNS schon einen lokalen Eintrag (→ "
                    .($existing['answer'] ?? '?').'). Core lässt ihn unverändert.']);

                return false;
            }
            $response = $client->post('/api/rewrites', $body);
            self::ensureOk($response);
            $link->fill(['dns_record_id' => (int) $response->json('id'), 'dns_answer' => $ip, 'dns_message' => null]);

            return true;
        } catch (Throwable $e) {
            $link->dns_message = 'Lokaler DNS-Eintrag nicht angelegt: '.$e->getMessage().' Core versucht es erneut.';

            return false;
        }
    }

    private static function ensureOk(Response $response): void
    {
        if (! $response->successful()) {
            throw new AppException((string) ($response->json('error') ?? 'HTTP '.$response->status()));
        }
    }

    /** The app if it runs (and the automation for it is switched on). */
    private function app(string $appId, ?string $setting = null): ?InstalledApp
    {
        if ($setting !== null && ! Settings::get($setting)) {
            return null;
        }

        return InstalledApp::query()->where('app_id', $appId)->where('status', InstalledApp::RUNNING)->first();
    }

    /**
     * @param  list<string>  $notes
     */
    private function createHost(InstalledApp $proxy, AppLink $link, bool $enabled, array &$notes): ?int
    {
        $client = $proxy->client();
        $existing = collect((array) $client->get('/api/hosts')->json())
            ->first(fn ($h) => in_array($link->domain, (array) ($h['domains'] ?? []), true));
        if ($existing !== null) {
            $notes[] = "Für {$link->domain} gibt es schon einen Host, er bleibt unverändert.";

            return null;
        }
        [$scheme, $host, $port] = self::parseTarget($link->target);
        $form = array_merge(Hosts::toForm(null), [
            'domains' => $link->domain, 'scheme' => $scheme, 'upstream_host' => $host, 'upstream_port' => (string) $port,
            // Apps behind HTTPS here use self-signed certificates.
            'skip_verify' => $scheme === 'https', 'block_exploits' => true, 'enabled' => $enabled,
            'notes' => trim("Automatisch: {$link->name} ({$link->hostname})"),
        ]);
        $response = $client->post('/api/hosts', Hosts::payload($form));
        if (! $response->successful()) {
            throw new AppException((string) ($response->json('error') ?? 'HTTP '.$response->status()));
        }

        return (int) $response->json('id');
    }

    /**
     * @param  array<string, mixed>  $monitor
     */
    private function createMonitor(InstalledApp $uptime, array $monitor): string
    {
        $response = $uptime->client()->post('/api/monitors', $monitor);
        if (! $response->successful()) {
            throw new AppException((string) ($response->json('error') ?? 'HTTP '.$response->status()));
        }

        return (string) $response->json('id');
    }

    private function delete(InstalledApp $app, string $path, string $what): ?string
    {
        try {
            $response = $app->client()->delete($path);

            return $response->successful() || $response->status() === 404 ? null : "{$what} nicht gelöscht: HTTP {$response->status()}.";
        } catch (Throwable $e) {
            return "{$what} nicht gelöscht: {$e->getMessage()}";
        }
    }

    private function where(AppLink $link): string
    {
        return $link->domain !== '' && $link->proxy_host_id ? 'https://'.$link->domain : $link->target;
    }

    /**
     * @return array{0: string, 1: string, 2: int}|null scheme, host, port
     */
    public static function parseTarget(string $url): ?array
    {
        $u = parse_url($url);
        if (! is_array($u) || ! in_array($u['scheme'] ?? '', ['http', 'https'], true) || empty($u['host'])) {
            return null;
        }

        return [$u['scheme'], trim($u['host'], '[]'), (int) ($u['port'] ?? ($u['scheme'] === 'https' ? 443 : 80))];
    }

    /**
     * Reachability of the app itself (any HTTP answer below 500: a login page or "not
     * configured yet" still means the app is up).
     *
     * @return array<string, mixed>
     */
    public static function internalMonitor(AppLink $link): array
    {
        return self::monitor("{$link->name} intern ({$link->hostname})", [
            'type' => 'http', 'url' => $link->target, 'method' => 'GET', 'acceptedStatus' => '200-499', 'keyword' => '',
            'keywordInverted' => false, 'followRedirects' => false, 'ignoreTlsErrors' => true, 'certWarnDays' => 0, // 0 = off
        ]);
    }

    /**
     * The public address through the proxy, with certificate warning.
     *
     * @return array<string, mixed>
     */
    public static function domainMonitor(AppLink $link): array
    {
        return self::monitor("{$link->name} ({$link->domain})", [
            'type' => 'http', 'url' => 'https://'.$link->domain.'/', 'method' => 'GET', 'acceptedStatus' => '200-399', 'keyword' => '',
            'keywordInverted' => false, 'followRedirects' => true, 'ignoreTlsErrors' => false, 'certWarnDays' => 14,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function monitor(string $name, array $config): array
    {
        return ['name' => mb_substr($name, 0, 100), 'group' => self::GROUP, 'intervalSeconds' => 60, 'timeoutSeconds' => 10,
            'retries' => 1, 'retryIntervalSeconds' => 30, 'config' => $config, 'channelIds' => []];
    }
}
