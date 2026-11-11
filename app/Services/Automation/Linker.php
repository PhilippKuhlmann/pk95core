<?php

namespace App\Services\Automation;

use App\Models\AppEvent;
use App\Models\AppLink;
use App\Models\InstalledApp;
use App\Modules\Proxy\Hosts;
use App\Services\Apps\AppClient;
use App\Services\Apps\AppException;
use App\Services\Catalog\Catalog;
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

    /** AppLink source of PK95 Uptime's public status pages (one link, source_id 1). */
    public const STATUS_SOURCE = 'uptime-status';

    public const STATUS_PORT = 3002;

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
            // the status pages' root path is not public: no domain monitor for them
            if ($link->source_app !== self::STATUS_SOURCE && ($uptime = $this->app('uptime', 'automation_uptime')) && count($ids) < 2) {
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
            $notes[] = $dnsApp ? $this->deleteRecord($dnsApp, $link)
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
     * PK95 Uptime's public status pages: a proxy host for the domain of uptime_public_url to
     * the port Uptime publishes for them on the LXC (<LAN-IP>:3002), and the local DNS record.
     * Idempotent: runs after every (re)creation of Uptime and from the button on the status
     * pages page. A changed LAN IP moves the host's target, a changed address takes down what
     * was set up for the old one. Problems end up in the link's message, never as an exception.
     */
    public function linkStatusPages(): ?AppLink
    {
        $url = trim((string) Settings::get('uptime_public_url'));
        $domain = strtolower((string) parse_url($url, PHP_URL_HOST));
        $link = AppLink::query()->firstOrNew(['source_app' => self::STATUS_SOURCE, 'source_id' => 1]);
        if ($link->exists && $link->state !== AppLink::REMOVED && $link->domain !== $domain) {
            $link = $this->unlink(self::STATUS_SOURCE, 1) ?? $link;
        }
        if ($domain === '') {
            return $link->exists ? $link : null;
        }
        $before = $link->exists ? [$link->state, $link->message] : null;
        $link->fill(['name' => 'Statusseiten', 'hostname' => 'PK95 Uptime', 'domain' => $domain,
            'monitor_ids' => [], 'checked_at' => now()]);
        $notes = [];
        $ip = $this->lanIp->current();
        $port = self::statusPort();
        $link->target = $ip !== null ? 'http://'.(str_contains($ip, ':') ? "[{$ip}]" : $ip).':'.$port : (string) $link->target;
        $enabled = false;
        $waiting = false; // an existing host that is still switched off: check DNS again below

        $proxy = $this->app('proxy', 'automation_proxy');
        if ($ip === null) {
            $notes[] = 'Die LAN-IP des Proxy ist nicht bekannt: kein Proxy-Host. Trag sie unter Automatik ein.';
        } elseif ($proxy === null) {
            $notes[] = InstalledApp::query()->where('app_id', 'proxy')->exists() && Settings::get('automation_proxy')
                ? 'PK95 Proxy läuft gerade nicht: noch kein Proxy-Host.'
                : 'Ohne PK95 Proxy (oder mit abgeschalteter Automatik) gibt es keinen Proxy-Host.';
        } else {
            try {
                $host = $link->proxy_host_id
                    ? collect((array) $proxy->client()->get('/api/hosts')->json())->firstWhere('id', $link->proxy_host_id)
                    : null;
                if ($host === null) {
                    // new, or the host was deleted in PK95 Proxy: create it (again)
                    $dns = $this->dns->check($domain);
                    $enabled = $dns['ok'];
                    $link->proxy_host_id = $this->createHost($proxy, $link, $enabled, $notes);
                    if ($link->proxy_host_id && ! $enabled) {
                        $notes[] = 'Host deaktiviert angelegt: '.$dns['reason'].' Core schaltet ihn ein, sobald DNS passt.';
                    }
                } else {
                    $enabled = (bool) ($host['enabled'] ?? false);
                    $waiting = ! $enabled;
                    [, $hostIp] = self::parseTarget($link->target) ?? [null, null];
                    if ((string) ($host['upstream_host'] ?? '') !== $hostIp || (int) ($host['upstream_port'] ?? 0) !== $port) {
                        $body = array_merge(array_intersect_key($host, array_flip(Hosts::FIELDS)),
                            ['scheme' => 'http', 'upstream_host' => $hostIp, 'upstream_port' => $port]);
                        self::ensureOk($proxy->client()->put('/api/hosts/'.$link->proxy_host_id, $body));
                    }
                }
            } catch (Throwable $e) {
                $notes[] = 'Proxy-Host nicht eingerichtet: '.$e->getMessage();
            }
        }
        $this->ensureRecord($link);

        $link->state = match (true) {
            ! $link->proxy_host_id => AppLink::FAILED,
            // Proxy not reachable this time: the host Core made earlier keeps its state
            $ip === null || $proxy === null => in_array($link->state, [AppLink::ACTIVE, AppLink::PENDING_DNS], true) ? $link->state : AppLink::FAILED,
            default => $enabled ? AppLink::ACTIVE : AppLink::PENDING_DNS,
        };
        $link->message = $notes ? implode(' ', $notes) : null;
        $link->save();
        if ($waiting) {
            $this->recheck($link); // switches the host on once the domain points here
            $link->refresh();
        }

        // an event only when something changed (this runs on every recreation of Uptime)
        if ($before !== [$link->state, $link->message]) {
            $details = trim(implode(' ', array_filter([$link->message, $link->dns_message])));
            AppEvent::record(AppEvent::CORE, 'core.link.'.$link->state, $link->state === AppLink::ACTIVE && $link->dns_message === null ? 'info' : 'warning',
                match ($link->state) {
                    AppLink::ACTIVE => "Statusseiten sind unter https://{$domain} eingerichtet",
                    AppLink::PENDING_DNS => "Statusseiten: {$domain} wartet auf DNS",
                    default => 'Statusseiten konnten nicht eingerichtet werden',
                }, $details !== '' ? $details : null, ['link' => $link->id, 'domain' => $domain]);
        }

        return $link;
    }

    /** The link of the status pages (null: never set up). */
    public static function statusLink(): ?AppLink
    {
        return AppLink::query()->where('source_app', self::STATUS_SOURCE)->where('source_id', 1)->first();
    }

    /** Port Uptime publishes for the status pages (PUBLIC_PORT of its catalog entry). */
    public static function statusPort(): int
    {
        try {
            return (int) (app(Catalog::class)->get('uptime')->env['PUBLIC_PORT'] ?? self::STATUS_PORT) ?: self::STATUS_PORT;
        } catch (Throwable) {
            return self::STATUS_PORT;
        }
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
        if ($link->dns_record_id && $link->dns_answer === $ip) {
            $link->dns_message = null;

            return false;
        }
        try {
            $client = $dnsApp->client();
            $found = $client->get('/api/records', ['name' => $link->domain]);
            if ($found->status() === 404) {
                return $this->ensureRewrite($client, $link, $ip); // PK95 DNS 1.0.x
            }
            self::ensureOk($found);
            $body = ['name' => $link->domain, 'type' => str_contains($ip, ':') ? 'AAAA' : 'A', 'value' => $ip, 'ttl' => 300,
                'enabled' => true, 'managed_by' => 'core'];
            $named = collect((array) $found->json())->filter(fn ($r) => strtolower((string) ($r['name'] ?? '')) === $link->domain);
            if ($link->dns_record_id) {
                $ours = $named->first(fn ($r) => (int) ($r['id'] ?? 0) === (int) $link->dns_record_id);
                if ($ours !== null && self::isCores($ours, $link)) {
                    self::ensureOk($client->put('/api/records/'.$link->dns_record_id, $body));
                    $link->fill(['dns_answer' => $ip, 'dns_message' => null]);

                    return true;
                }
                // deleted or renamed in PK95 DNS in the meantime (create it again), or taken
                // over there (managed_by cleared: no longer Core's, left alone below)
                $link->dns_record_id = null;
            }
            // A name with an address or alias that Core did not create stays as it is. Other
            // types (TXT, MX, …) of the same name do not compete with Core's A/AAAA record.
            $existing = $named->first(fn ($r) => in_array($r['type'] ?? '', ['A', 'AAAA', 'CNAME'], true));
            if ($existing !== null) {
                $link->fill(['dns_answer' => '', 'dns_message' => "Für {$link->domain} gibt es in PK95 DNS schon einen Eintrag ("
                    .($existing['type'] ?? '?').' → '.($existing['value'] ?? '?').'). Core lässt ihn unverändert.']);

                return false;
            }
            $response = $client->post('/api/records', $body);
            self::ensureOk($response);
            $link->fill(['dns_record_id' => (int) $response->json('id'), 'dns_answer' => $ip, 'dns_message' => null]);

            return true;
        } catch (Throwable $e) {
            $link->dns_message = 'Lokaler DNS-Eintrag nicht angelegt: '.$e->getMessage().' Core versucht es erneut.';

            return false;
        }
    }

    /**
     * ensureRecord() for PK95 DNS 1.0.x, which only knows /api/rewrites (domain → answer).
     */
    private function ensureRewrite(AppClient $client, AppLink $link, string $ip): bool
    {
        $body = ['domain' => $link->domain, 'answer' => $ip, 'enabled' => true];
        if ($link->dns_record_id) {
            $response = $client->put('/api/rewrites/'.$link->dns_record_id, $body);
            if ($response->status() !== 404) {
                self::ensureOk($response);
                $link->fill(['dns_answer' => $ip, 'dns_message' => null]);

                return true;
            }
            $link->dns_record_id = null;
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
    }

    /**
     * Deletes Core's record. A record someone took over in PK95 DNS (managed_by cleared and
     * a different address) stays. PK95 DNS 1.0.x only knows /api/rewrites (same ids); a
     * record that is gone already needs nothing.
     */
    private function deleteRecord(InstalledApp $dnsApp, AppLink $link): ?string
    {
        $id = (int) $link->dns_record_id;
        try {
            $client = $dnsApp->client();
            $found = $client->get('/api/records', ['name' => $link->domain]);
            if ($found->status() === 404) {
                $response = $client->delete('/api/rewrites/'.$id); // PK95 DNS 1.0.x
            } else {
                self::ensureOk($found);
                $record = collect((array) $found->json())->first(fn ($r) => (int) ($r['id'] ?? 0) === $id);
                if ($record === null) {
                    return null; // removed or renamed in PK95 DNS already
                }
                if (! self::isCores($record, $link)) {
                    return "DNS-Eintrag {$link->domain} bleibt: Er wurde in PK95 DNS übernommen.";
                }
                $response = $client->delete('/api/records/'.$id);
            }

            return $response->successful() || $response->status() === 404 ? null : "DNS-Eintrag nicht gelöscht: HTTP {$response->status()}.";
        } catch (Throwable $e) {
            return "DNS-Eintrag nicht gelöscht: {$e->getMessage()}";
        }
    }

    /**
     * Whether Core may change or remove the record of a link: marked as Core's, or migrated
     * from PK95 DNS 1.0 (no marker yet) and still exactly the address Core wrote.
     *
     * @param  array<string, mixed>  $record
     */
    private static function isCores(array $record, AppLink $link): bool
    {
        $by = (string) ($record['managed_by'] ?? '');

        return $by === 'core' || ($by === '' && $link->dns_answer !== '' && (string) ($record['value'] ?? '') === $link->dns_answer);
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
