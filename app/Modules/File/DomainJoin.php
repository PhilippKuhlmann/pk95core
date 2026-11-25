<?php

namespace App\Modules\File;

use App\Models\InstalledApp;
use Illuminate\Http\Client\Response;

/**
 * The domain join of PK95 File, orchestrated by Core (the file app never talks to PK95 AD;
 * pk95file docs/design.md §4):
 *
 *   1. GET ad:/api/domain                           realm, NetBIOS domain, DC name and IP
 *   2. DNS: A record <hostname>.<realm> → IP        through PK95 AD, skipped when it exists with
 *                                                   that IP, refused when the name points elsewhere
 *   3. POST ad:/api/computers/odj                   the offline-join blob (reset: rejoin)
 *   4. POST file:/api/setup (or /api/join)          with the blob
 *
 * The blob carries the machine password of the new account. It exists only as a local variable
 * of join() and goes straight from AD's answer into the request to the file server: never into
 * a property, the database, the cache, the session, a log, an event or an error message.
 * The password fallback (join_user/join_password) skips step 3; the password is not kept either.
 *
 * DNS comes before the blob (deviating from the order in design.md) so a retry after a failed
 * step finds the record again instead of a half-created computer account.
 */
final class DomainJoin
{
    public const DESCRIPTION = 'PK95 File';

    public function __construct(private readonly InstalledApp $file, private readonly InstalledApp $ad) {}

    /**
     * Joins (setup) or rejoins the file server. $input: hostname, ip, ip_mode, allow_system_disk
     * (setup) or hostname (rejoin). Returns the file app's answer (202 = it runs); throws
     * JoinFailed for a step before.
     *
     * @param  array<string, mixed>  $input
     * @param  array{join_user: string, join_password: string}|null  $credentials  null = offline join (ODJ)
     *
     * @throws JoinFailed
     */
    public function join(array $input, bool $rejoin, bool $reset, #[\SensitiveParameter] ?array $credentials = null): Response
    {
        $domain = $this->domain();
        $hostname = (string) $input['hostname'];
        $fqdn = $hostname.'.'.$domain['realm'];

        $body = [];
        if (! $rejoin) {
            $this->ensureDns($domain['realm'], $fqdn, (string) $input['ip']);
            $body = [
                'hostname' => $hostname,
                'ip' => (string) $input['ip'],
                'ip_mode' => (string) $input['ip_mode'],
                'realm' => $domain['realm'],
                'workgroup' => $domain['netbios_domain'],
                'dc' => $domain['dc'],
            ];
        }

        if ($credentials !== null) {
            $body['join_user'] = $credentials['join_user'];
            $body['join_password'] = $credentials['join_password'];
            unset($credentials);
        } else {
            $answer = $this->ad->client()->post('/api/computers/odj', [
                'name' => $hostname, 'dns_hostname' => $fqdn, 'description' => self::DESCRIPTION, 'reset' => $reset,
            ]);
            if (! $answer->successful()) {
                throw self::odjFailed($answer, $hostname);
            }
            $body['odj'] = (string) $answer->json('odj');
            unset($answer);
            if ($body['odj'] === '') {
                throw new JoinFailed('PK95 AD hat keine Beitrittsdaten geliefert.', 'odj');
            }
        }
        if (! $rejoin) {
            $body['allow_system_disk'] = (bool) ($input['allow_system_disk'] ?? false);
        }

        $response = $this->file->client()->post($rejoin ? '/api/join' : '/api/setup', $body);
        unset($body);

        return $response;
    }

    /**
     * realm (lowercase), netbios_domain and dc {hostname, ip} from PK95 AD.
     *
     * @return array{realm: string, netbios_domain: string, dc: array{hostname: string, ip: string}}
     *
     * @throws JoinFailed
     */
    public function domain(): array
    {
        $response = $this->ad->client()->get('/api/domain');
        if ($response->status() === 409) {
            throw new JoinFailed('Die Domäne in PK95 AD ist noch nicht eingerichtet.', 'domain');
        }
        if (! $response->successful()) {
            throw new JoinFailed('PK95 AD liefert die Domäne nicht: '.self::error($response), 'domain');
        }
        $realm = strtolower(trim((string) $response->json('realm')));
        if ($realm === '') {
            throw new JoinFailed('PK95 AD liefert keine Domäne.', 'domain');
        }

        return [
            'realm' => $realm,
            'netbios_domain' => (string) $response->json('netbios_domain'),
            'dc' => ['hostname' => (string) $response->json('dc.hostname'), 'ip' => (string) $response->json('dc.ip')],
        ];
    }

    /**
     * The A record <fqdn> → $ip in the AD zone: created when missing, left alone when it
     * exists with that address; any other record of that name stops the join (never overwritten).
     *
     * @return bool true when it was created, false when it was there
     *
     * @throws JoinFailed
     */
    public function ensureDns(string $zone, string $fqdn, string $ip): bool
    {
        $path = '/api/dns/zones/'.rawurlencode($zone).'/records';
        $list = $this->ad->client()->get($path, ['q' => $fqdn]);
        if (! $list->successful()) {
            throw new JoinFailed('Die DNS-Einträge von PK95 AD lassen sich nicht lesen: '.self::error($list), 'dns');
        }
        $same = array_values(array_filter((array) $list->json(), fn ($r) => is_array($r) && self::sameName((string) ($r['name'] ?? ''), $fqdn)));
        foreach ($same as $record) {
            if (($record['type'] ?? '') !== 'A' || trim((string) ($record['value'] ?? '')) !== $ip) {
                throw new JoinFailed("Der DNS-Name {$fqdn} zeigt schon auf ".($record['value'] ?? '?').' ('.($record['type'] ?? '?').'). '
                    .'Wähle einen anderen Servernamen oder ändere den Eintrag in PK95 AD unter DNS.', 'dns');
            }
        }
        if ($same !== []) {
            return false;
        }
        $created = $this->ad->client()->post($path, ['name' => $fqdn, 'type' => 'A', 'value' => $ip, 'ttl' => 900]);
        if (! $created->successful()) {
            throw new JoinFailed("Der DNS-Eintrag {$fqdn} → {$ip} ließ sich nicht anlegen: ".self::error($created), 'dns');
        }

        return true;
    }

    /** a failed blob request as JoinFailed: 409 = the account exists (reset offered), 422 = the name */
    private static function odjFailed(Response $answer, string $hostname): JoinFailed
    {
        $message = self::error($answer);
        if ($answer->status() === 409) {
            return new JoinFailed($message, 'exists');
        }
        $fields = [];
        foreach ((array) $answer->json('fields') as $field => $text) {
            $fields[in_array($field, ['name', 'dns_hostname'], true) ? 'hostname' : (string) $field] = (string) $text;
        }

        return new JoinFailed('PK95 AD hat das Computerkonto '.strtoupper($hostname).' nicht angelegt: '.$message, 'odj', $fields);
    }

    private static function error(Response $response): string
    {
        return (string) ($response->json('error') ?? "HTTP {$response->status()}");
    }

    private static function sameName(string $a, string $b): bool
    {
        return strcasecmp(rtrim($a, '.'), rtrim($b, '.')) === 0;
    }
}
