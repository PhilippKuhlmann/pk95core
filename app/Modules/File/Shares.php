<?php

namespace App\Modules\File;

use App\Modules\File\Concerns\FilePage;
use App\Services\Apps\AppException;
use Livewire\Component;

/**
 * PK95 File → Freigaben: list with status and usage against the soft quota; create and edit
 * (description, enabled, access-based enumeration, permissions by AD group SID, recycle bin,
 * snapshots, soft quota „Kontingent (Warnung)“, adopting a left-over folder), delete with or
 * without the data, the snapshots of a share (dialog: list, "Jetzt sichern", delete) and the
 * shortcut „Als Netzlaufwerk verteilen“ to PK95 AD's drive maps.
 *
 * Writes: POST /api/shares (with permissions), PUT /api/shares/{name} and, when the rights
 * changed, PUT /api/shares/{name}/permissions; 422 fields land on the form (nested names like
 * "snapshots.keep.daily", "permissions.2.sid").
 */
class Shares extends Component
{
    use FilePage;

    /** null closed, 'new' or the name of the share being edited */
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<array{sid: string, access: string}> permissions as loaded (change check) */
    public array $originalPermissions = [];

    /** @var list<array<string, mixed>> fixed permissions of the share in the dialog (read-only) */
    public array $fixed = [];

    /** snapshot estimate of the share in the dialog (bytes, 0 = unknown) */
    public int $estimate = 0;

    /** the app said the folder exists: "Übernehmen" is offered */
    public bool $offerAdopt = false;

    /** snapshots dialog: the share name */
    public ?string $snapshotsFor = null;

    /** delete dialog: the share name */
    public ?string $deleting = null;

    public bool $deleteData = false;

    public string $deleteConfirm = '';

    public const ACCESS = ['read' => 'Lesen', 'change' => 'Ändern', 'full' => 'Vollzugriff'];

    public const WEEKDAYS = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    /** status → label, tone */
    public const STATUS = [
        'ok' => ['In Ordnung', 'green'],
        'soft_quota_warning' => ['Kontingent fast voll', 'amber'],
        'soft_quota_exceeded' => ['Kontingent überschritten', 'rose'],
        'locked' => ['Schreibgeschützt', 'rose'],
        'disabled' => ['Deaktiviert', 'gray'],
    ];

    public const GIB = 1073741824;

    // --- dialog --------------------------------------------------------------------------

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = self::blank();
        $this->originalPermissions = [];
        $this->fixed = [];
        $this->estimate = 0;
        $this->offerAdopt = false;
        $this->editing = 'new';
    }

    public function edit(string $name): void
    {
        $this->resetErrorBag();
        $response = $this->appRequest(fn ($api) => $api->get(self::shareUrl($name)));
        if (! $this->succeeded($response, '')) {
            return;
        }
        $share = (array) $response->json();
        $this->form = self::toForm($share);
        $this->originalPermissions = self::permissionsToApi($this->form['permissions']);
        $this->fixed = array_values((array) ($share['fixed_permissions'] ?? []));
        $this->estimate = (int) ($share['snapshots']['estimate_bytes'] ?? 0);
        $this->offerAdopt = false;
        $this->editing = (string) ($share['name'] ?? $name);
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->snapshotsFor = null;
        $this->deleting = null;
        $this->deleteConfirm = '';
        $this->offerAdopt = false;
        $this->resetErrorBag();
    }

    public function addPermission(): void
    {
        $this->form['permissions'][] = ['sid' => '', 'access' => 'change', 'name' => '', 'unresolved' => false];
    }

    public function removePermission(int $index): void
    {
        unset($this->form['permissions'][$index]);
        $this->form['permissions'] = array_values($this->form['permissions']);
        $this->resetErrorBag();
    }

    /** the folder of the name exists (data of a deleted share): take it over with its rights */
    public function adopt(): void
    {
        $this->form['adopt'] = true;
        $this->save();
    }

    public function save(): void
    {
        $this->resetErrorBag();
        foreach ($this->form['permissions'] as $i => $row) {
            if (trim((string) $row['sid']) === '') {
                $this->addError('form.permissions.'.$i.'.sid', 'Wähle eine Gruppe.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $body = self::toApi($this->form);
        $permissions = self::permissionsToApi($this->form['permissions']);

        if ($this->editing === 'new') {
            $name = trim((string) $this->form['name']);
            $body = ['name' => $name] + $body + ['permissions' => $permissions] + (! empty($this->form['adopt']) ? ['adopt' => true] : []);
            $response = $this->appRequest(fn ($api) => $api->post('/api/shares', $body));
            if ($this->succeeded($response)) {
                $this->done('Freigabe '.$name.' angelegt.');
                $this->editing = null;
                $this->offerAdopt = false;

                return;
            }
            // „Der Ordner existiert bereits. Übernehmen?“
            $this->offerAdopt = $response !== null && str_contains((string) $response->json('fields.name'), 'Übernehmen');
            $this->form['adopt'] = false;

            return;
        }

        $name = (string) $this->editing;
        $response = $this->appRequest(fn ($api) => $api->put(self::shareUrl($name), $body));
        if (! $this->succeeded($response)) {
            return;
        }
        $propagate = (bool) ($this->form['propagate'] ?? false);
        $text = 'Freigabe '.$name.' gespeichert.';
        if ($permissions !== $this->originalPermissions || $propagate) {
            $rights = $this->appRequest(fn ($api) => $api->put(self::shareUrl($name).'/permissions', ['permissions' => $permissions, 'propagate' => $propagate]));
            if (! $this->succeeded($rights)) {
                return;
            }
            $this->originalPermissions = $permissions;
            $text = $rights->status() === 202
                ? 'Freigabe '.$name.' gespeichert. Die Rechte werden im Hintergrund auf alle Unterordner übertragen.'
                : 'Freigabe '.$name.' und ihre Rechte gespeichert.';
        }
        $this->done($text);
        $this->editing = null;
    }

    /**
     * App field → form field: lists edited as one text ("recycle.exclude.3" → "recycle.exclude"),
     * the schedule without its level, the quota in GB; the rest keeps its nested name.
     */
    protected function mapField(string $field): string
    {
        if (preg_match('/^recycle\.exclude(\.\d+)?$/', $field)) {
            return 'recycle.exclude';
        }
        if (preg_match('/^snapshots\.schedule\.(times|weekdays)(\.\d+)?$/', $field, $m)) {
            return 'snapshots.'.$m[1];
        }

        return $field === 'soft_quota.size_bytes' ? 'soft_quota.size_gb' : $field;
    }

    // --- delete --------------------------------------------------------------------------

    public function confirmDelete(string $name): void
    {
        $this->resetErrorBag();
        $this->deleting = $name;
        $this->deleteData = false;
        $this->deleteConfirm = '';
    }

    /** without data: the folder, recycle bin and snapshots stay; with data only after typing the name */
    public function deleteShare(): void
    {
        $this->resetErrorBag();
        $name = (string) $this->deleting;
        if ($name === '') {
            return;
        }
        if ($this->deleteData && trim($this->deleteConfirm) !== $name) {
            $this->addError('deleteConfirm', 'Zum Löschen der Daten den Namen der Freigabe genau eintippen.');

            return;
        }
        $response = $this->appRequest(fn ($api) => $api->delete(self::shareUrl($name).'?delete_data='.($this->deleteData ? '1' : '0')));
        if (! $this->succeeded($response, '')) {
            return;
        }
        $this->done($this->deleteData
            ? 'Freigabe '.$name.' wird mit allen Daten gelöscht.'
            : 'Freigabe '.$name.' entfernt. Der Ordner, der Papierkorb und die Vorgängerversionen bleiben erhalten.');
        $this->deleting = null;
        $this->deleteConfirm = '';
        $this->deleteData = false;
    }

    // --- snapshots -----------------------------------------------------------------------

    public function openSnapshots(string $name): void
    {
        $this->resetErrorBag();
        $this->snapshotsFor = $name;
    }

    public function snapshotNow(): void
    {
        $name = (string) $this->snapshotsFor;
        $response = $this->appRequest(fn ($api) => $api->post(self::shareUrl($name).'/snapshots'));
        if ($this->succeeded($response, '')) {
            $this->done('Die Sicherung von '.$name.' läuft. Sie erscheint hier, sobald sie fertig ist.');
        }
    }

    public function deleteSnapshot(string $id): void
    {
        $name = (string) $this->snapshotsFor;
        $response = $this->appRequest(fn ($api) => $api->delete(self::shareUrl($name).'/snapshots/'.rawurlencode($id)));
        if ($this->succeeded($response, '')) {
            $this->done('Die Vorgängerversion wird gelöscht.');
        }
    }

    // --- conversion ----------------------------------------------------------------------

    /** @return array<string, mixed> a new share with the app's documented defaults */
    public static function blank(): array
    {
        return [
            'name' => '', 'description' => '', 'enabled' => true, 'abe' => true, 'permissions' => [], 'propagate' => false, 'adopt' => false,
            'recycle' => ['enabled' => true, 'keep_days' => '30', 'max_file_mb' => '0', 'exclude' => '*.tmp, ~$*, *.~*'],
            'snapshots' => ['enabled' => true, 'times' => '07:00, 12:00, 17:00', 'weekdays' => ['1', '2', '3', '4', '5'],
                'keep' => ['recent' => '12', 'daily' => '14', 'weekly' => '8']],
            'soft_quota' => ['size_gb' => '', 'warn_percent' => '90', 'lock_when_exceeded' => false],
        ];
    }

    /**
     * @param  array<string, mixed>  $share
     * @return array<string, mixed>
     */
    public static function toForm(array $share): array
    {
        $recycle = (array) ($share['recycle'] ?? []);
        $snap = (array) ($share['snapshots'] ?? []);
        $quota = (array) ($share['soft_quota'] ?? []);
        $size = (int) ($quota['size_bytes'] ?? 0);

        return [
            'name' => (string) ($share['name'] ?? ''),
            'description' => (string) ($share['description'] ?? ''),
            'enabled' => (bool) ($share['enabled'] ?? true),
            'abe' => (bool) ($share['abe'] ?? true),
            'permissions' => array_values(array_map(fn ($p) => [
                'sid' => (string) ($p['sid'] ?? ''), 'access' => (string) ($p['access'] ?? 'read'),
                'name' => (string) ($p['name'] ?? ''), 'unresolved' => (bool) ($p['unresolved'] ?? false),
            ], (array) ($share['permissions'] ?? []))),
            'propagate' => false,
            'adopt' => false,
            'recycle' => [
                'enabled' => (bool) ($recycle['enabled'] ?? true),
                'keep_days' => (string) ($recycle['keep_days'] ?? 30),
                'max_file_mb' => (string) ($recycle['max_file_mb'] ?? 0),
                'exclude' => implode(', ', array_map('strval', (array) ($recycle['exclude'] ?? []))),
            ],
            'snapshots' => [
                'enabled' => (bool) ($snap['enabled'] ?? false),
                'times' => implode(', ', array_map('strval', (array) ($snap['schedule']['times'] ?? []))),
                'weekdays' => array_values(array_map('strval', (array) ($snap['schedule']['weekdays'] ?? []))),
                'keep' => [
                    'recent' => (string) ($snap['keep']['recent'] ?? 12),
                    'daily' => (string) ($snap['keep']['daily'] ?? 14),
                    'weekly' => (string) ($snap['keep']['weekly'] ?? 8),
                ],
            ],
            'soft_quota' => [
                'size_gb' => $size > 0 ? self::gb($size) : '',
                'warn_percent' => (string) ($quota['warn_percent'] ?? 90),
                'lock_when_exceeded' => (bool) ($quota['lock_when_exceeded'] ?? false),
            ],
        ];
    }

    /**
     * The writable fields except name, permissions and adopt (PUT /api/shares/{name}).
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function toApi(array $form): array
    {
        $weekdays = array_map(fn ($d) => (int) $d, (array) $form['snapshots']['weekdays']);
        sort($weekdays);

        return [
            'description' => trim((string) $form['description']),
            'enabled' => (bool) $form['enabled'],
            'abe' => (bool) $form['abe'],
            'recycle' => [
                'enabled' => (bool) $form['recycle']['enabled'],
                'keep_days' => self::int($form['recycle']['keep_days']),
                'max_file_mb' => self::int($form['recycle']['max_file_mb']),
                'exclude' => self::list((string) $form['recycle']['exclude']),
            ],
            'snapshots' => [
                'enabled' => (bool) $form['snapshots']['enabled'],
                'schedule' => ['times' => self::list((string) $form['snapshots']['times']), 'weekdays' => array_values(array_unique($weekdays))],
                'keep' => [
                    'recent' => self::int($form['snapshots']['keep']['recent']),
                    'daily' => self::int($form['snapshots']['keep']['daily']),
                    'weekly' => self::int($form['snapshots']['keep']['weekly']),
                ],
            ],
            'soft_quota' => [
                'size_bytes' => self::bytesFromGb((string) $form['soft_quota']['size_gb']),
                'warn_percent' => self::int($form['soft_quota']['warn_percent']),
                'lock_when_exceeded' => (bool) $form['soft_quota']['lock_when_exceeded'],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{sid: string, access: string}>
     */
    public static function permissionsToApi(array $rows): array
    {
        return array_values(array_map(fn ($r) => ['sid' => trim((string) $r['sid']), 'access' => (string) $r['access']],
            array_filter($rows, fn ($r) => trim((string) ($r['sid'] ?? '')) !== '')));
    }

    /** a number field: digits → int, anything else as typed (the app answers 422 on it) */
    private static function int(mixed $value): int|string
    {
        $value = trim((string) $value);

        return preg_match('/^\d+$/', $value) ? (int) $value : $value;
    }

    /** "*.tmp, ~$*" or one per line → list */
    private static function list(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $text) ?: []), fn ($s) => $s !== ''));
    }

    /** "100" or "1,5" (GB = GiB) → bytes; empty = 0 = no quota; anything else as typed (422) */
    public static function bytesFromGb(string $gb): int|string
    {
        $gb = str_replace(',', '.', trim($gb));
        if ($gb === '') {
            return 0;
        }

        return is_numeric($gb) && (float) $gb >= 0 ? (int) round((float) $gb * self::GIB) : $gb;
    }

    /** bytes → "100" / "1,5" (GiB, for the form) */
    public static function gb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / self::GIB, 2, ',', ''), '0'), ',');
    }

    // --- loading -------------------------------------------------------------------------

    /**
     * Security groups of PK95 AD with their SIDs for the permission picker; rows of the share
     * whose SID the list does not carry stay selectable with their stored name. [options, hint]
     *
     * @return array{0: list<array{value: string, label: string, hint?: string}>, 1: ?string}
     */
    private function groupOptions(): array
    {
        $options = [];
        $hint = null;
        $ad = self::runningAd();
        if ($ad === null) {
            $hint = 'PK95 AD läuft nicht: Gruppen lassen sich gerade nicht auswählen.';
        } else {
            try {
                $response = $ad->client()->get('/api/groups', ['type' => 'security']);
                $items = $response->successful() ? (array) $response->json('items') : [];
                if (! $response->successful()) {
                    $hint = 'PK95 AD liefert die Gruppen nicht: '.($response->json('error') ?? 'HTTP '.$response->status());
                }
            } catch (AppException $e) {
                $items = [];
                $hint = $e->getMessage();
            }
            $withoutSid = 0;
            foreach ($items as $group) {
                $sid = (string) ($group['sid'] ?? '');
                if ($sid === '') {
                    $withoutSid++;

                    continue;
                }
                $options[$sid] = ['value' => $sid, 'label' => (string) ($group['name'] ?? $sid), 'hint' => (string) ($group['ou_path'] ?? '')];
            }
            if ($withoutSid > 0 && $options === []) {
                $hint = 'Die Gruppenauswahl braucht PK95 AD 1.5.0 oder neuer.';
            }
        }
        foreach ((array) ($this->form['permissions'] ?? []) as $row) {
            $sid = (string) $row['sid'];
            if ($sid !== '' && ! isset($options[$sid])) {
                $options[$sid] = ['value' => $sid, 'label' => $row['name'] !== '' ? $row['name'] : 'Unbekannt ('.$sid.')', 'hint' => $row['unresolved'] ? 'gelöscht' : ''];
            }
        }

        return [array_values($options), $hint];
    }

    public function render()
    {
        [$list, $loadError] = $this->fetch('/api/shares');
        $shares = is_array($list) ? array_values((array) ($list['items'] ?? [])) : [];
        $ad = self::runningAd();

        [$groupOptions, $groupHint] = $this->editing !== null ? $this->groupOptions() : [[], null];
        $snapshots = null;
        $snapshotsError = null;
        if ($this->snapshotsFor !== null && ! $this->notReady) {
            [$snapshots, $snapshotsError] = $this->load(self::shareUrl($this->snapshotsFor).'/snapshots');
        }

        return view('modules.file.shares', [
            'shares' => $shares,
            'loadError' => $loadError,
            'groupOptions' => $groupOptions,
            'groupHint' => $groupHint,
            'snapshots' => is_array($snapshots) ? $snapshots : null,
            'snapshotsError' => $snapshotsError,
            // „Als Netzlaufwerk verteilen“: PK95 AD's GPO page with a drive row for the share's UNC
            'driveLink' => $ad ? fn (array $share) => route('apps.page', ['installedApp' => $ad, 'page' => 'gpo', 'laufwerk' => (string) ($share['unc'] ?? '')]) : null,
        ]);
    }
}
