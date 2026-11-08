<?php

namespace App\Modules\Uptime;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 Uptime: monitor list with live status, details, create/edit form and actions.
 * Data comes from the Uptime API (/api/monitors…); nothing is stored in Core.
 */
class Monitors extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    /** null = list only, 'new' = create form, otherwise the id of the monitor being edited */
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** Monitor whose details are open. */
    public ?string $expanded = null;

    public string $search = '';

    public const TYPES = [
        'http' => 'HTTP(S)',
        'tcp' => 'TCP-Port',
        'ping' => 'Ping',
        'dns' => 'DNS',
        'certificate' => 'Zertifikat',
        'push' => 'Push',
    ];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = self::defaults();
        $this->editing = 'new';
    }

    public function edit(string $id): void
    {
        $this->resetErrorBag();
        $response = $this->appRequest(fn ($api) => $api->get('/api/monitors/'.rawurlencode($id)));
        if ($this->succeeded($response)) {
            $this->form = self::fromDetail($response->json());
            $this->editing = $id;
        }
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $payload = $this->payload();
        $new = $this->editing === 'new';
        $response = $this->appRequest(fn ($api) => $new
            ? $api->post('/api/monitors', $payload)
            : $api->put('/api/monitors/'.rawurlencode((string) $this->editing), $payload));

        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: $new ? 'Monitor angelegt.' : 'Monitor gespeichert.');
            $this->expanded = $response->json('id');
            $this->editing = null;
        }
    }

    public function pause(string $id): void
    {
        $this->action('/api/monitors/'.rawurlencode($id).'/pause', 'Monitor pausiert.');
    }

    public function resume(string $id): void
    {
        $this->action('/api/monitors/'.rawurlencode($id).'/resume', 'Monitor läuft wieder.');
    }

    public function checkNow(string $id): void
    {
        $this->action('/api/monitors/'.rawurlencode($id).'/check-now', 'Prüfung ausgeführt.');
    }

    public function delete(string $id): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/monitors/'.rawurlencode($id)));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: 'Monitor gelöscht.');
            $this->expanded = $this->expanded === $id ? null : $this->expanded;
        }
    }

    public function toggle(string $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function render()
    {
        [$monitors, $error] = $this->load('/api/monitors');
        $monitors = is_array($monitors) ? $monitors : [];

        $needle = mb_strtolower(trim($this->search));
        if ($needle !== '') {
            $monitors = array_values(array_filter($monitors, fn (array $m) => str_contains(mb_strtolower($m['name'].' '.$m['target'].' '.$m['group']), $needle)));
        }
        usort($monitors, fn (array $a, array $b) => [$a['group'] === '' ? 1 : 0, mb_strtolower($a['group']), mb_strtolower($a['name'])]
            <=> [$b['group'] === '' ? 1 : 0, mb_strtolower($b['group']), mb_strtolower($b['name'])]);

        $detail = null;
        $incidents = [];
        if ($this->expanded !== null) {
            [$detail] = $this->load('/api/monitors/'.rawurlencode($this->expanded));
            [$incidents] = $this->load('/api/monitors/'.rawurlencode($this->expanded).'/incidents', ['limit' => 10]);
        }

        $counts = array_count_values(array_column($monitors, 'status'));

        return view('modules.uptime.monitors', [
            'groups' => collect($monitors)->groupBy('group'),
            'counts' => $counts,
            'total' => count($monitors),
            'loadError' => $error,
            'detail' => is_array($detail) ? $detail : null,
            'incidents' => is_array($incidents) ? $incidents : [],
            'types' => self::TYPES,
        ]);
    }

    // ------------------------------------------------------------------------------------

    protected function mapField(string $field): string
    {
        // Uptime reports config fields as "config.url"; the form keeps them flat.
        return str_starts_with($field, 'config.') ? substr($field, 7) : $field;
    }

    private function action(string $path, string $success): void
    {
        $response = $this->appRequest(fn ($api) => $api->post($path));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: $success);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'name' => '', 'group' => '', 'type' => 'http',
            'intervalSeconds' => 60, 'timeoutSeconds' => 10, 'retries' => 1, 'retryIntervalSeconds' => 30,
            'url' => 'https://', 'method' => 'GET', 'acceptedStatus' => '200-299', 'keyword' => '', 'keywordInverted' => false,
            'followRedirects' => true, 'ignoreTlsErrors' => false, 'certWarnDays' => 14,
            'host' => '', 'port' => 443, 'recordType' => 'A', 'expected' => '', 'resolver' => '',
            'graceSeconds' => 60, 'warnDays' => 14,
            'channelIds' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $detail  MonitorDetail of the Uptime API
     * @return array<string, mixed>
     */
    public static function fromDetail(array $detail): array
    {
        $config = (array) ($detail['config'] ?? []);
        $form = array_merge(self::defaults(), array_intersect_key($config, self::defaults()), [
            'name' => $detail['name'] ?? '',
            'group' => $detail['group'] ?? '',
            'type' => $config['type'] ?? 'http',
            'intervalSeconds' => $detail['intervalSeconds'] ?? 60,
            'timeoutSeconds' => $detail['timeoutSeconds'] ?? 10,
            'retries' => $detail['retries'] ?? 0,
            'retryIntervalSeconds' => $detail['retryIntervalSeconds'] ?? 30,
            'channelIds' => $detail['channelIds'] ?? [],
        ]);
        foreach (['keyword', 'expected', 'resolver'] as $optional) {
            $form[$optional] ??= '';
        }

        return $form;
    }

    /**
     * MonitorInput for the Uptime API, built from the flat form.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $f = $this->form;
        $int = fn (string $key) => is_numeric($f[$key] ?? null) ? (int) $f[$key] : null;
        $bool = fn (string $key) => filter_var($f[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        $text = fn (string $key) => trim((string) ($f[$key] ?? ''));

        $config = match ($f['type'] ?? 'http') {
            'http' => [
                'type' => 'http', 'url' => $text('url'), 'method' => $text('method') ?: 'GET',
                'acceptedStatus' => $text('acceptedStatus'), 'keyword' => $text('keyword'), 'keywordInverted' => $bool('keywordInverted'),
                'followRedirects' => $bool('followRedirects'), 'ignoreTlsErrors' => $bool('ignoreTlsErrors'), 'certWarnDays' => $int('certWarnDays'),
            ],
            'tcp' => ['type' => 'tcp', 'host' => $text('host'), 'port' => $int('port')],
            'ping' => ['type' => 'ping', 'host' => $text('host')],
            'dns' => ['type' => 'dns', 'host' => $text('host'), 'recordType' => $text('recordType'), 'expected' => $text('expected'), 'resolver' => $text('resolver')],
            'certificate' => ['type' => 'certificate', 'host' => $text('host'), 'port' => $int('port'), 'warnDays' => $int('warnDays')],
            'push' => ['type' => 'push', 'graceSeconds' => $int('graceSeconds')],
            default => ['type' => (string) $f['type']],
        };

        return [
            'name' => $text('name'),
            'group' => $text('group'),
            'intervalSeconds' => $int('intervalSeconds'),
            'timeoutSeconds' => $int('timeoutSeconds'),
            'retries' => $int('retries'),
            'retryIntervalSeconds' => $int('retryIntervalSeconds'),
            'config' => $config,
            // Core mode: notifications come from Core; existing channel links are kept.
            'channelIds' => array_values((array) ($f['channelIds'] ?? [])),
        ];
    }
}
