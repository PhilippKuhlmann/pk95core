<?php

namespace App\Modules\File;

use App\Models\InstalledApp;
use App\Modules\File\Concerns\FilePage;
use App\Services\Apps\AppException;
use Livewire\Component;

/**
 * PK95 File → Dateiserver: the setup wizard while GET /api/setup says state != ready (needs
 * PK95 AD set up; Core joins the server through DomainJoin: domain data, DNS record, offline
 * join blob, POST /api/setup, then polling), afterwards the dashboard (storage, counts,
 * snapshots, warnings), the status (smbd, winbindd, trust, IP, Unix IDs), "Neu beitreten" and
 * the settings (GET/PUT /api/settings).
 *
 * The blob is never a property here: it lives inside DomainJoin::join(). The fallback password
 * is a form field that is emptied in the same request that sends it.
 */
class Server extends Component
{
    use FilePage;

    /** @var array{hostname: string, ip: string, ip_mode: string, allow_system_disk: bool} */
    public array $setupForm = ['hostname' => 'files', 'ip' => '', 'ip_mode' => 'managed', 'allow_system_disk' => false];

    /** @var array{ip: string, ok: bool, message: string}|null result of "IP prüfen" */
    public ?array $ipCheck = null;

    /** set while this page watched a join: done/failed follow as a toast */
    public bool $watching = false;

    /** the last join this page started used the blob (not the password) */
    public bool $joinedWithOdj = false;

    /** a step before the file server got the request failed (German, from Core or PK95 AD) */
    public ?string $joinError = null;

    /** PK95 AD answered 409: the computer account exists → "Neu verbinden" (reset) */
    public bool $offerReset = false;

    /** the offline join failed → the password fallback is offered */
    public bool $odjFailed = false;

    /** fallback join: a domain admin, used once (not stored by Core nor by the app) */
    public string $joinUser = 'Administrator';

    /** fallback join: emptied in the request that sends it */
    public string $joinPassword = '';

    /** @var array<string, mixed> the settings form (GET /api/settings) */
    public array $settings = [];

    /** What PK95 File asks Core to show after saving the settings (e.g. about macOS), until the next save. */
    public ?string $settingsNotice = null;

    public const IP_MODES = [
        'managed' => 'PK95 File legt die Adresse selbst an (empfohlen)',
        'existing' => 'Adresse ist im LXC schon eingerichtet (z. B. net1)',
    ];

    public const SMB_ENCRYPTION = [
        'if_required' => 'Wenn der Client es verlangt',
        'desired' => 'Bevorzugt',
        'required' => 'Immer (ältere Clients können nicht verbinden)',
    ];

    public const MIN_PROTOCOL = ['SMB2_10' => 'SMB 2.1 (Windows 7 und neuer)', 'SMB3_00' => 'SMB 3.0 (Windows 8 und neuer)', 'SMB3_11' => 'SMB 3.1.1 (Windows 10 und neuer)'];

    public const SETTINGS = [
        'scan_interval_minutes' => ['Belegung prüfen alle', 'Minuten, 15 bis 1440'],
        'disk_warn_percent' => ['Warnung ab freiem Platz unter', 'Prozent, 1 bis 50'],
        'disk_critical_percent' => ['Kritisch ab freiem Platz unter', 'Prozent, kleiner als die Warnung'],
        'snapshot_min_free_percent' => ['Vorgängerversionen aussetzen unter', 'Prozent freiem Platz, 5 bis 50'],
    ];

    public const SERVICE_STATES = ['running' => 'läuft', 'starting' => 'startet', 'restarting' => 'startet neu', 'backup' => 'angehalten (Sicherung)', 'stopped' => 'gestoppt'];

    public function mount(): void
    {
        [$setup] = $this->fetch('/api/setup');
        if (! is_array($setup) || ($setup['state'] ?? '') === 'ready') {
            return;
        }
        $defaults = (array) ($setup['defaults'] ?? []);
        $this->setupForm['hostname'] = (string) (($defaults['hostname'] ?? '') ?: 'files');
        $this->setupForm['ip'] = (string) ($defaults['ip'] ?? '');
        $this->watching = ($setup['state'] ?? '') === 'joining';
        // a failed setup: the password fallback is the other way in
        $this->odjFailed = ($setup['state'] ?? '') === 'failed';
    }

    public function updatedSetupFormHostname(): void
    {
        $this->setupForm['hostname'] = strtolower(trim($this->setupForm['hostname']));
    }

    public function updatedSetupFormIp(): void
    {
        $this->setupForm['ip'] = trim($this->setupForm['ip']);
        $this->ipCheck = null;
    }

    /** managed: the suggested address; existing: the first address of the LXC that is not the DC's */
    public function updatedSetupFormIpMode(): void
    {
        $this->ipCheck = null;
        $mode = $this->setupForm['ip_mode'] === 'existing' ? 'existing' : 'managed';
        $this->setupForm['ip_mode'] = $mode;
        [$setup] = $this->fetch('/api/setup');
        $defaults = (array) ($setup['defaults'] ?? []);
        $this->setupForm['ip'] = $mode === 'managed' ? (string) ($defaults['ip'] ?? '')
            : (string) (self::existingIps($defaults, $this->dcIp())[0] ?? '');
    }

    /** GET /api/setup/ip-check: free in the subnet (managed) or present in the LXC (existing) */
    public function checkIp(): void
    {
        $this->resetErrorBag('setupForm.ip');
        $ip = trim($this->setupForm['ip']);
        if ($ip === '') {
            $this->addError('setupForm.ip', 'Bitte eine IP-Adresse angeben.');

            return;
        }
        $this->ipCheck = $this->ipCheckResult($ip);
    }

    /** first join; $reset after PK95 AD said the computer account exists ("Neu verbinden") */
    public function startSetup(bool $reset = false): void
    {
        $this->join($reset, null);
    }

    /** state ready: a new blob (the account gets a new password) → POST /api/join */
    public function rejoin(): void
    {
        $this->join(true, null);
    }

    /** the fallback after a failed offline join: a domain admin's password, used once */
    public function joinWithPassword(): void
    {
        $credentials = ['join_user' => trim($this->joinUser), 'join_password' => $this->joinPassword];
        $this->joinPassword = '';
        if ($credentials['join_user'] === '' || $credentials['join_password'] === '') {
            $this->addError('joinPassword', 'Bitte Benutzer und Kennwort angeben.');

            return;
        }
        $this->join(false, $credentials);
    }

    /**
     * @param  array{join_user: string, join_password: string}|null  $credentials
     */
    private function join(bool $reset, #[\SensitiveParameter] ?array $credentials): void
    {
        $this->resetErrorBag();
        $this->joinError = null;
        $this->offerReset = false;

        [$setup, $error] = $this->fetch('/api/setup');
        if (! is_array($setup)) {
            $this->joinError = $error ?? 'PK95 File antwortet nicht.';

            return;
        }
        $state = (string) ($setup['state'] ?? '');
        if ($state === 'joining') {
            $this->watching = true;

            return;
        }
        $rejoin = $state === 'ready';
        $ad = self::runningAd();
        if ($ad === null) {
            $this->joinError = 'PK95 AD läuft nicht. Der Dateiserver tritt der Domäne über PK95 AD bei.';

            return;
        }

        if ($rejoin) {
            $input = ['hostname' => (string) ($setup['server']['hostname'] ?? '')];
            if ($input['hostname'] === '') {
                $this->joinError = 'PK95 File nennt keinen Servernamen.';

                return;
            }
        } else {
            $input = $this->validSetupInput($setup);
            if ($input === null) {
                return;
            }
        }

        try {
            $response = (new DomainJoin($this->installedApp, $ad))->join($input, $rejoin, $reset, $credentials);
        } catch (JoinFailed $e) {
            $this->joinError = $e->getMessage();
            $this->offerReset = $e->kind === 'exists';
            $this->odjFailed = $this->odjFailed || ($credentials === null && $e->kind === 'odj');
            foreach ($e->fields as $field => $message) {
                $this->addError('setupForm.'.$field, $message);
            }

            return;
        } catch (AppException $e) {
            $this->joinError = $e->getMessage();

            return;
        }

        if ($response->successful()) {
            $this->watching = true;
            $this->joinedWithOdj = $credentials === null;
            $this->ipCheck = null;
            $this->done($rejoin ? 'Der Dateiserver tritt der Domäne neu bei.' : 'Der Dateiserver tritt der Domäne bei. Das dauert etwa eine halbe Minute.');

            return;
        }
        // the file server refused: field errors to the form, the blob itself ("odj") opens the fallback
        $fields = (array) $response->json('fields');
        if ($credentials === null && array_key_exists('odj', $fields)) {
            $this->odjFailed = true;
        }
        foreach ($fields as $field => $message) {
            $this->addError('setupForm.'.$field, (string) $message);
        }
        $this->joinError = (string) ($response->json('error') ?? "PK95 File antwortet mit HTTP {$response->status()}.");
    }

    /**
     * The wizard's values, checked before anything is written in PK95 AD: hostname, IPv4 and
     * the IP check of the app; null with field errors.
     *
     * @param  array<string, mixed>  $setup
     * @return array{hostname: string, ip: string, ip_mode: string, allow_system_disk: bool}|null
     */
    private function validSetupInput(array $setup): ?array
    {
        $hostname = strtolower(trim($this->setupForm['hostname']));
        $ip = trim($this->setupForm['ip']);
        $mode = $this->setupForm['ip_mode'] === 'existing' ? 'existing' : 'managed';
        if (! preg_match('/^(?![0-9]+$)[a-z0-9]([a-z0-9-]{0,13}[a-z0-9])?$/', $hostname)) {
            $this->addError('setupForm.hostname', '1 bis 15 Zeichen a–z, 0–9 und Bindestrich, nicht nur Ziffern, ohne Bindestrich am Anfang oder Ende.');
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->addError('setupForm.ip', 'Bitte eine IPv4-Adresse angeben.');
        }
        if (($setup['storage']['problems'] ?? []) !== []) {
            $this->joinError = 'Der Datenordner ist nicht bereit (siehe oben).';
        }
        if (($setup['storage']['on_system_disk'] ?? false) && ! $this->setupForm['allow_system_disk']) {
            $this->addError('setupForm.allow_system_disk', 'Bitte bestätigen oder in Proxmox einen eigenen Mountpoint einhängen.');
        }
        if ($this->getErrorBag()->isNotEmpty() || $this->joinError !== null) {
            return null;
        }
        // the address before PK95 AD gets an account and a DNS record for it
        $check = $this->ipCheckResult($ip);
        $this->ipCheck = $check;
        if ($check === null) {
            return null;
        }
        if (! $check['ok']) {
            $this->addError('setupForm.ip', $check['message'] !== '' ? $check['message'] : 'Die Adresse ist nicht frei.');

            return null;
        }

        return ['hostname' => $hostname, 'ip' => $ip, 'ip_mode' => $mode, 'allow_system_disk' => (bool) $this->setupForm['allow_system_disk']];
    }

    /** @return array{ip: string, ok: bool, message: string}|null null: the check failed (toast) */
    private function ipCheckResult(string $ip): ?array
    {
        $query = ['ip' => $ip] + ($this->setupForm['ip_mode'] === 'existing' ? ['mode' => 'existing'] : []);
        $response = $this->appRequest(fn ($api) => $api->get('/api/setup/ip-check', $query));
        if (! $this->succeeded($response, 'setupForm.')) {
            return null;
        }

        return ['ip' => $ip, 'ok' => (bool) $response->json('ok'), 'message' => (string) $response->json('message')];
    }

    public function saveSettings(): void
    {
        $this->resetErrorBag();
        $body = [];
        foreach (array_keys(self::SETTINGS) as $key) {
            $value = trim((string) ($this->settings[$key] ?? ''));
            $body[$key] = preg_match('/^\d+$/', $value) ? (int) $value : $value;
        }
        $body['smb_encryption'] = (string) ($this->settings['smb_encryption'] ?? 'if_required');
        $body['min_protocol'] = (string) ($this->settings['min_protocol'] ?? 'SMB2_10');
        // since PK95 File 1.1.0; older versions do not know the field, so it is only sent when GET had it
        if (array_key_exists('macos', $this->settings)) {
            $body['macos'] = (bool) $this->settings['macos'];
        }
        $response = $this->appRequest(fn ($api) => $api->put('/api/settings', $body));
        if ($this->succeeded($response, 'settings.')) {
            $answer = (array) $response->json();
            // "notice" (1.1.0): a German text to show, empty unless macos changed
            $notice = trim((string) ($answer['notice'] ?? ''));
            unset($answer['notice']);
            $this->settings = $answer;
            $this->settingsNotice = $notice !== '' ? $notice : null;
            $this->done('Einstellungen gespeichert. Samba lädt sie neu, die Sitzungen bleiben.');
        }
    }

    /**
     * Addresses for ip_mode existing: those of the LXC except the DC's.
     *
     * @param  array<string, mixed>  $defaults
     * @return list<string>
     */
    public static function existingIps(array $defaults, string $dcIp): array
    {
        return array_values(array_filter(array_map('strval', (array) ($defaults['host_ips'] ?? [])), fn ($ip) => $ip !== $dcIp));
    }

    private function dcIp(): string
    {
        $ad = self::runningAd();
        if ($ad === null) {
            return '';
        }
        try {
            $response = $ad->client()->get('/api/domain');
        } catch (AppException) {
            return '';
        }

        return $response->successful() ? (string) $response->json('dc.ip') : '';
    }

    /**
     * What the wizard needs from PK95 AD: state missing | stopped | error | unconfigured | ready,
     * the app, the realm and the DC.
     *
     * @return array{state: string, app: ?InstalledApp, realm: string, dc: array<string, mixed>, error: ?string}
     */
    private function adState(): array
    {
        $ad = self::adApp();
        $result = ['state' => 'missing', 'app' => $ad, 'realm' => '', 'dc' => [], 'error' => null];
        if ($ad === null) {
            return $result;
        }
        if ($ad->status !== InstalledApp::RUNNING) {
            return ['state' => 'stopped'] + $result;
        }
        try {
            $setup = $ad->client()->get('/api/setup');
            if (! $setup->successful()) {
                return ['state' => 'error', 'error' => (string) ($setup->json('error') ?? "PK95 AD antwortet mit HTTP {$setup->status()}.")] + $result;
            }
            if ($setup->json('state') !== 'ready') {
                return ['state' => 'unconfigured'] + $result;
            }
            $domain = $ad->client()->get('/api/domain');
        } catch (AppException $e) {
            return ['state' => 'error', 'error' => $e->getMessage()] + $result;
        }

        return ['state' => 'ready', 'realm' => strtolower((string) $domain->json('realm')), 'dc' => (array) $domain->json('dc')] + $result;
    }

    /**
     * Storage bar of the dashboard: segments in percent of the total and the tone of the free
     * part (warn/bad from the app's disk warning, else from free_percent and the settings).
     *
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $settings
     * @return array{segments: list<array{key: string, label: string, bytes: int, percent: float}>, tone: string, free_percent: int}
     */
    public static function storageBar(array $dashboard, array $settings = []): array
    {
        $s = (array) ($dashboard['storage'] ?? []);
        $total = max(1, (int) ($s['total_bytes'] ?? 0));
        $free = (int) ($s['free_bytes'] ?? 0);
        $parts = ['share_bytes' => 'Freigaben', 'snapshot_bytes' => 'Vorgängerversionen', 'recycle_bytes' => 'Papierkorb'];
        $segments = [];
        $known = 0;
        foreach ($parts as $key => $label) {
            $bytes = max(0, (int) ($s[$key] ?? 0));
            $known += $bytes;
            $segments[] = ['key' => $key, 'label' => $label, 'bytes' => $bytes, 'percent' => $bytes / $total * 100];
        }
        // what the scan does not know (system files, data written since the scan)
        $other = max(0, $total - $free - $known);
        $segments[] = ['key' => 'other', 'label' => 'Sonstiges', 'bytes' => $other, 'percent' => $other / $total * 100];
        $segments[] = ['key' => 'free', 'label' => 'Frei', 'bytes' => $free, 'percent' => $free / $total * 100];

        $freePercent = (int) ($s['free_percent'] ?? round($free / $total * 100));
        $disk = collect((array) ($dashboard['warnings'] ?? []))->firstWhere('code', 'disk');
        $tone = match (true) {
            is_array($disk) => ($disk['level'] ?? '') === 'critical' ? 'bad' : 'warn',
            $freePercent < (int) ($settings['disk_critical_percent'] ?? 10) => 'bad',
            $freePercent < (int) ($settings['disk_warn_percent'] ?? 20) => 'warn',
            default => 'ok',
        };

        return ['segments' => $segments, 'tone' => $tone, 'free_percent' => $freePercent];
    }

    public function render()
    {
        [$setup, $loadError] = $this->fetch('/api/setup');
        $state = is_array($setup) ? (string) ($setup['state'] ?? '') : '';
        $data = ['setup' => $setup, 'state' => $state, 'loadError' => $loadError, 'ad' => null,
            'dashboard' => null, 'status' => null, 'dashboardError' => null, 'settingsError' => null];

        // the poll after a join this page started
        if ($this->watching && in_array($state, ['ready', 'failed'], true)) {
            $this->watching = false;
            if ($state === 'ready') {
                $this->done('Der Dateiserver ist in der Domäne.');
            } else {
                $this->odjFailed = $this->odjFailed || $this->joinedWithOdj;
                $this->dispatch('notice', kind: 'error', text: 'Der Beitritt ist fehlgeschlagen.');
            }
        }

        if ($state !== 'ready' && is_array($setup)) {
            $data['ad'] = $this->adState();
        }
        if ($state === 'ready') {
            [$data['dashboard'], $data['dashboardError']] = $this->load('/api/dashboard');
            [$data['status']] = $this->load('/api/status');
            if ($this->settings === []) {
                [$settings, $data['settingsError']] = $this->load('/api/settings');
                $this->settings = is_array($settings) ? $settings : [];
            }
        }

        return view('modules.file.server', $data);
    }
}
