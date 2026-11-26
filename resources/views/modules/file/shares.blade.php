@php
    use App\Modules\File\Shares;
    use Illuminate\Support\Js;
    $badge = 'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium';
    $tones = [
        'green' => 'bg-green-100 text-green-800 dark:bg-green-900/60 dark:text-green-100',
        'amber' => 'bg-amber-100 text-amber-900 dark:bg-amber-900/60 dark:text-amber-100',
        'rose' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/60 dark:text-rose-100',
        'gray' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    ];
    $isNew = $editing === 'new';
    $dialogTitle = $isNew ? 'Freigabe anlegen' : 'Freigabe '.$editing;
    $saveLabel = $isNew ? 'Anlegen' : 'Speichern';
    $deleteTitle = 'Freigabe '.$deleting.' löschen';
    $deleteLabel = $deleteData ? 'Mit allen Daten löschen' : 'Freigabe entfernen';
    $snapshotsTitle = 'Vorgängerversionen von '.$snapshotsFor;
@endphp

<div>
    <x-page-header title="Freigaben">
        <x-slot:sub>Ordner auf dem Dateiserver, die Windows-Rechner als Netzlaufwerk verbinden. Rechte vergibst du über Gruppen von PK95 AD.</x-slot:sub>
        @if (! $notReady && $editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Freigabe anlegen</x-input.button>
        @endif
    </x-page-header>

    @include('modules.file.partials.state')

    {{-- create / edit --}}
    @if ($editing !== null)
        <x-modal wire:key="share-form" submit="save" size="2xl" :save-label="$saveLabel">
            <x-slot:title>{{ $dialogTitle }}</x-slot:title>
            <div class="mt-2 grid grid-cols-1 gap-4 md:grid-cols-2">
                @if ($isNew)
                    <x-field label="Name" for="sh-name" feld="form.name" hilfe="Lässt sich später nicht ändern. Endet der Name mit $, ist die Freigabe im Explorer versteckt." :required="true">
                        <x-input.text id="sh-name" feld="form.name" wire:model="form.name" maxlength="80" placeholder="Projekte" class="mt-1 w-full" />
                    </x-field>
                @endif
                <x-field label="Beschreibung" for="sh-desc" feld="form.description" :class="$isNew ? '' : 'md:col-span-2'">
                    <x-input.text id="sh-desc" feld="form.description" wire:model="form.description" maxlength="256" class="mt-1 w-full" />
                </x-field>
                @if ($offerAdopt)
                    <x-callout title="Der Ordner existiert bereits" ton="gelb" class="md:col-span-2" data-adopt>
                        Von einer gelöschten Freigabe mit diesem Namen sind die Daten noch da. Übernehmen behält die Dateien, den Papierkorb, die Vorgängerversionen und die Rechte im Ordner.
                        <x-slot:aktionen>
                            <x-input.button type="button" size="sm" wire:click="adopt">Übernehmen</x-input.button>
                        </x-slot:aktionen>
                    </x-callout>
                @endif
                <div class="flex flex-col gap-1 md:col-span-2">
                    <x-input.checkbox wire:model="form.enabled" label="Freigabe aktiv" hilfe="Ausgeschaltet bleibt der Ordner erhalten, ist aber nicht erreichbar." />
                    <x-input.checkbox wire:model="form.abe" label="Nur sichtbar, was man lesen darf" hilfe="Ordner und Dateien ohne Leserecht blendet der Explorer aus (Access-based Enumeration)." />
                </div>
            </div>

            <x-form-section title="Berechtigungen" />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Gruppen aus PK95 AD und ihr Zugriff auf die Freigabe. Ändern = lesen, schreiben und löschen; Vollzugriff darf zusätzlich Rechte ändern.</p>
            @if ($groupHint)
                <p class="mt-1 text-xs text-amber-800 dark:text-amber-300" data-group-hint>{{ $groupHint }}</p>
            @endif
            <div class="mt-2 space-y-2" data-permissions>
                @foreach ($form['permissions'] as $i => $row)
                    <div class="flex flex-wrap items-start gap-2" wire:key="perm-{{ $i }}">
                        <div class="min-w-48 flex-1">
                            <x-input.combobox wire:model="form.permissions.{{ $i }}.sid" label="Gruppe {{ $i + 1 }}" :options="$groupOptions" :search="true" placeholder="Gruppe wählen …" />
                            <x-input.fehler :feld="'form.permissions.'.$i.'.sid'" />
                        </div>
                        <div class="w-40">
                            <x-input.select :feld="'form.permissions.'.$i.'.access'" wire:model="form.permissions.{{ $i }}.access" aria-label="Zugriff">
                                @foreach (Shares::ACCESS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                            </x-input.select>
                            <x-input.fehler :feld="'form.permissions.'.$i.'.access'" />
                        </div>
                        <x-input.symbolknopf titel="Zeile entfernen" ton="rot" wire:click="removePermission({{ $i }})"><x-icon name="trash" class="h-4 w-4" /></x-input.symbolknopf>
                    </div>
                @endforeach
                <x-input.fehler feld="form.permissions" />
                <x-input.button type="button" size="sm" color="gray" wire:click="addPermission"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Gruppe hinzufügen</x-input.button>
            </div>
            @if ($fixed)
                <ul class="mt-3 space-y-1 text-xs text-gray-600 dark:text-gray-300" data-fixed-permissions>
                    @foreach ($fixed as $p)
                        <li class="inline-flex w-full items-center gap-1.5"><x-icon name="lock" class="h-3 w-3" aria-hidden="true" />{{ $p['name'] ?? $p['sid'] }}: {{ Shares::ACCESS[$p['access'] ?? ''] ?? ($p['access'] ?? '') }} <span class="text-gray-400">(immer)</span></li>
                    @endforeach
                </ul>
            @endif
            @unless ($isNew)
                <div class="mt-2">
                    <x-input.checkbox wire:model="form.propagate" label="Auch auf alle Unterordner übertragen"
                        hilfe="Setzt die geerbten Rechte darunter neu (läuft im Hintergrund). Eigene Rechte einzelner Ordner bleiben." />
                </div>
            @endunless

            <x-form-section title="Papierkorb" />
            <div class="mt-2 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="md:col-span-3"><x-input.checkbox wire:model="form.recycle.enabled" label="Gelöschte Dateien in den Papierkorb" /></div>
                <x-field label="Aufbewahren" for="sh-keep" feld="form.recycle.keep_days" hilfe="Tage, 1 bis 365">
                    <x-input.text id="sh-keep" type="number" min="1" max="365" feld="form.recycle.keep_days" wire:model="form.recycle.keep_days" class="mt-1 w-full" />
                </x-field>
                <x-field label="Größte Datei" for="sh-max" feld="form.recycle.max_file_mb" hilfe="MB, 0 = ohne Grenze">
                    <x-input.text id="sh-max" type="number" min="0" feld="form.recycle.max_file_mb" wire:model="form.recycle.max_file_mb" class="mt-1 w-full" />
                </x-field>
                <x-field label="Nicht aufheben" for="sh-excl" feld="form.recycle.exclude" hilfe="Muster, durch Komma getrennt">
                    <x-input.text id="sh-excl" feld="form.recycle.exclude" wire:model="form.recycle.exclude" class="mt-1 w-full font-mono" />
                </x-field>
            </div>

            <x-form-section title="Vorgängerversionen" />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-snapshot-note>
                Vorgängerversionen brauchen etwa das Doppelte an Platz.
                @if ($estimate > 0) Für diese Freigabe rechnet PK95 File mit etwa {{ Shares::bytes($estimate) }} zusätzlich. @endif
                Benutzer holen ältere Stände selbst zurück: Explorer → Eigenschaften → Vorgängerversionen.
            </p>
            <div class="mt-2 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="md:col-span-3"><x-input.checkbox wire:model="form.snapshots.enabled" label="Vorgängerversionen anlegen" /></div>
                <x-field label="Uhrzeiten" for="sh-times" feld="form.snapshots.times" hilfe="HH:MM, durch Komma getrennt" class="md:col-span-2">
                    <x-input.text id="sh-times" feld="form.snapshots.times" wire:model="form.snapshots.times" placeholder="07:00, 12:00, 17:00" class="mt-1 w-full font-mono" />
                </x-field>
                <x-field label="Tage" feld="form.snapshots.weekdays">
                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1">
                        @foreach (Shares::WEEKDAYS as $d => $l)
                            <label class="inline-flex items-center gap-1 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" value="{{ $d }}" wire:model="form.snapshots.weekdays" class="rounded border-gray-300 text-cerulean-600 focus:ring-cerulean-500 dark:border-gray-600 dark:bg-gray-700">{{ $l }}
                            </label>
                        @endforeach
                    </div>
                </x-field>
                <x-field label="Letzte behalten" for="sh-recent" feld="form.snapshots.keep.recent" hilfe="1 bis 100">
                    <x-input.text id="sh-recent" type="number" min="1" feld="form.snapshots.keep.recent" wire:model="form.snapshots.keep.recent" class="mt-1 w-full" />
                </x-field>
                <x-field label="Tägliche behalten" for="sh-daily" feld="form.snapshots.keep.daily" hilfe="Tage, 0 bis 365">
                    <x-input.text id="sh-daily" type="number" min="0" feld="form.snapshots.keep.daily" wire:model="form.snapshots.keep.daily" class="mt-1 w-full" />
                </x-field>
                <x-field label="Wöchentliche behalten" for="sh-weekly" feld="form.snapshots.keep.weekly" hilfe="Wochen, 0 bis 104">
                    <x-input.text id="sh-weekly" type="number" min="0" feld="form.snapshots.keep.weekly" wire:model="form.snapshots.keep.weekly" class="mt-1 w-full" />
                </x-field>
            </div>

            <x-form-section title="Kontingent (Warnung)" />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Kein hartes Limit: Der Explorer zeigt die Freigabe mit dieser Größe, PK95 File warnt ab dem Schwellwert. Viele kleine Dateien kommen trotzdem durch.</p>
            <div class="mt-2 grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-field label="Größe" for="sh-quota" feld="form.soft_quota.size_gb" hilfe="GB, leer = kein Kontingent">
                    <x-input.text id="sh-quota" inputmode="decimal" feld="form.soft_quota.size_gb" wire:model="form.soft_quota.size_gb" placeholder="100" class="mt-1 w-full" />
                </x-field>
                <x-field label="Warnen ab" for="sh-warn" feld="form.soft_quota.warn_percent" hilfe="Prozent, 50 bis 99">
                    <x-input.text id="sh-warn" type="number" min="50" max="99" feld="form.soft_quota.warn_percent" wire:model="form.soft_quota.warn_percent" class="mt-1 w-full" />
                </x-field>
                <div class="md:col-span-3">
                    <x-input.checkbox wire:model="form.soft_quota.lock_when_exceeded" label="Bei Überschreitung schreibgeschützt schalten"
                        hilfe="Erst die nächste Prüfung der Belegung schaltet um, bis dahin kann die Freigabe weiter wachsen. Danach können Benutzer auch nichts mehr löschen." />
                </div>
            </div>
        </x-modal>
    @endif

    {{-- delete --}}
    @if ($deleting !== null)
        <x-modal wire:key="share-delete" submit="deleteShare" size="md" :save-label="$deleteLabel">
            <x-slot:title>{{ $deleteTitle }}</x-slot:title>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                Die Freigabe verschwindet vom Dateiserver, verbundene Laufwerke funktionieren nicht mehr. Ohne Häkchen bleiben der Ordner,
                sein Papierkorb und die Vorgängerversionen erhalten; eine neue Freigabe mit diesem Namen kann sie übernehmen.
            </p>
            <div class="mt-3">
                <x-input.checkbox wire:model.live="deleteData" label="Auch alle Daten löschen" hilfe="Ordner, Papierkorb und Vorgängerversionen. Das lässt sich nicht rückgängig machen." />
            </div>
            @if ($deleteData)
                <x-field label="Zum Bestätigen den Namen der Freigabe eintippen" for="sh-del" feld="deleteConfirm" class="mt-3">
                    <x-input.text id="sh-del" feld="deleteConfirm" wire:model="deleteConfirm" autocomplete="off" class="mt-1 w-full font-mono" />
                </x-field>
            @endif
        </x-modal>
    @endif

    {{-- snapshots of one share --}}
    @if ($snapshotsFor !== null)
        <x-modal wire:key="share-snapshots" submit="" size="xl">
            <x-slot:title>{{ $snapshotsTitle }}</x-slot:title>
            @php $hostManaged = ($snapshots['backend'] ?? '') === 'zfs-host'; @endphp
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-gray-600 dark:text-gray-300" data-restore-hint>Wiederherstellen über Windows-Explorer → Vorgängerversionen <span class="text-gray-500 dark:text-gray-400">(Rechtsklick auf die Datei oder den Ordner → Eigenschaften).</span></p>
                @unless ($hostManaged)
                    <x-input.button type="button" size="sm" wire:click="snapshotNow" wire:loading.attr="disabled" wire:target="snapshotNow" data-snapshot-now>Jetzt sichern</x-input.button>
                @endunless
            </div>
            @if ($hostManaged)
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Die Snapshots verwaltet der Proxmox-Host.</p>
            @endif
            @if ($snapshotsError)
                <p class="mt-3 text-sm text-amber-800 dark:text-amber-300">{{ $snapshotsError }}</p>
            @elseif (! ($snapshots['items'] ?? []))
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Noch keine Vorgängerversionen.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm" data-snapshots>
                        <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr><th class="px-2 py-2">Stand</th><th class="hidden px-2 py-2 sm:table-cell">Dateien</th><th class="px-2 py-2">Eigener Platz</th><th class="hidden px-2 py-2 md:table-cell">Behalten als</th><th class="px-2 py-2"><span class="sr-only">Aktionen</span></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ((array) $snapshots['items'] as $s)
                                <tr wire:key="snap-{{ $s['id'] }}">
                                    <td class="px-2 py-2 align-top">{{ Shares::when($s['created_at'] ?? 0) }}@if ($s['manual'] ?? false)<span class="{{ $badge }} ml-1 {{ $tones['gray'] }}">von Hand</span>@endif</td>
                                    <td class="hidden px-2 py-2 align-top tabular-nums sm:table-cell">{{ number_format((int) ($s['files'] ?? 0), 0, ',', '.') }}</td>
                                    <td class="px-2 py-2 align-top tabular-nums">{{ Shares::bytes($s['unique_bytes'] ?? 0) }}</td>
                                    <td class="hidden px-2 py-2 align-top text-gray-600 md:table-cell dark:text-gray-300">{{ implode(', ', array_map(fn ($k) => ['recent' => 'letzte', 'daily' => 'täglich', 'weekly' => 'wöchentlich'][$k] ?? $k, (array) ($s['kept_by'] ?? []))) ?: '–' }}</td>
                                    <td class="px-2 py-2 text-right align-top">
                                        @unless ($hostManaged)
                                            <x-input.symbolknopf titel="Vorgängerversion löschen" ton="rot"
                                                x-on:click="$store.confirm.ask({ title: 'Vorgängerversion löschen?', text: {{ Js::from('Der Stand vom '.Shares::when($s['created_at'] ?? 0).' wird gelöscht.') }}, button: 'Löschen', ok: () => $wire.deleteSnapshot({{ Js::from($s['id']) }}) })">
                                                <x-icon name="trash" class="h-4 w-4" />
                                            </x-input.symbolknopf>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-modal>
    @endif

    @if (! $notReady)
        @if (! $shares && ! $loadError)
            <x-emptystate message="Noch keine Freigaben." />
        @elseif ($shares)
            @php
                $rows = array_map(function (array $sh) {
                    [$label, $tone] = Shares::STATUS[$sh['status'] ?? 'ok'] ?? [$sh['status'] ?? '', 'gray'];
                    $used = (int) ($sh['usage']['bytes'] ?? 0);
                    $quota = (int) ($sh['soft_quota']['size_bytes'] ?? 0);

                    return ['sh' => $sh, 'label' => $label, 'tone' => $tone, 'used' => $used, 'quota' => $quota,
                        'pct' => $quota > 0 ? min(100, (int) round($used / $quota * 100)) : null,
                        'barTone' => $tone === 'rose' ? 'bg-rose-500' : ($tone === 'amber' ? 'bg-amber-500' : 'bg-cerulean-500'),
                        'unresolved' => collect((array) ($sh['permissions'] ?? []))->where('unresolved', true)->count(),
                        'rights' => Shares::permissionSummary($sh)];
                }, $shares);
                $unresolvedText = fn (int $n) => $n === 1 ? 'Ein Recht gehört zu einer gelöschten Gruppe.' : $n.' Rechte gehören zu gelöschten Gruppen.';
                $cardLabel = 'text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400';
            @endphp

            {{-- below md: one card per share --}}
            <div class="m-3 space-y-3 md:hidden" data-share-cards>
                @foreach ($rows as $r)
                    @php $sh = $r['sh']; @endphp
                    <x-panel polster="eng" wire:key="share-card-{{ $sh['name'] }}" data-share-card="{{ $sh['name'] }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <button type="button" wire:click="edit({{ Js::from($sh['name']) }})" class="text-left font-DINPro-bold break-all text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $sh['name'] }}</button>
                                @if ($sh['hidden'] ?? false)<span class="{{ $badge }} ml-1 {{ $tones['gray'] }}">versteckt</span>@endif
                                @if (($sh['description'] ?? '') !== '')<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $sh['description'] }}</span>@endif
                            </div>
                            <span class="{{ $badge }} shrink-0 {{ $tones[$r['tone']] }}">{{ $r['label'] }}</span>
                        </div>
                        @if ($r['unresolved'])<p class="mt-1 text-xs text-amber-800 dark:text-amber-300">{{ $unresolvedText($r['unresolved']) }}</p>@endif
                        <dl class="mt-3 space-y-2 text-sm text-gray-900 dark:text-gray-100">
                            <div><dt class="{{ $cardLabel }}">Pfad</dt><dd><x-copy :value="$sh['unc'] ?? ''" class="font-mono text-xs" /></dd></div>
                            <div><dt class="{{ $cardLabel }}">Rechte</dt><dd class="text-gray-700 dark:text-gray-300" data-rights>{{ $r['rights'] }}</dd></div>
                            <div><dt class="{{ $cardLabel }}">Belegung</dt><dd>@include('modules.file.partials.share-usage')</dd></div>
                        </dl>
                        <div class="mt-3 border-t border-gray-100 pt-2 dark:border-gray-700">@include('modules.file.partials.share-actions')</div>
                    </x-panel>
                @endforeach
            </div>

            {{-- from md: the table --}}
            <x-panel class="relative m-3 hidden overflow-x-auto md:block" polster="keins">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-shares>
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-3">Name</th><th class="hidden px-4 py-3 xl:table-cell">Pfad</th><th class="hidden px-4 py-3 xl:table-cell">Rechte</th><th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Belegung</th><th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rows as $r)
                            @php $sh = $r['sh']; @endphp
                            <tr wire:key="share-{{ $sh['name'] }}" data-share="{{ $sh['name'] }}">
                                <td class="min-w-48 px-4 py-3 align-top">
                                    <button type="button" wire:click="edit({{ Js::from($sh['name']) }})" class="text-left font-DINPro-bold text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $sh['name'] }}</button>
                                    @if ($sh['hidden'] ?? false)<span class="{{ $badge }} ml-1 {{ $tones['gray'] }}">versteckt</span>@endif
                                    @if (($sh['description'] ?? '') !== '')<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $sh['description'] }}</span>@endif
                                    @if ($r['unresolved'])<span class="block text-xs text-amber-800 dark:text-amber-300">{{ $unresolvedText($r['unresolved']) }}</span>@endif
                                    <span class="mt-1 block xl:hidden"><x-copy :value="$sh['unc'] ?? ''" class="font-mono text-xs text-gray-600 dark:text-gray-300" /></span>
                                </td>
                                <td class="hidden px-4 py-3 align-top xl:table-cell"><x-copy :value="$sh['unc'] ?? ''" class="font-mono text-xs" /></td>
                                <td class="hidden max-w-64 px-4 py-3 align-top text-xs text-gray-700 xl:table-cell dark:text-gray-300">{{ $r['rights'] }}</td>
                                <td class="px-4 py-3 align-top"><span class="{{ $badge }} {{ $tones[$r['tone']] }}" data-status="{{ $sh['status'] ?? '' }}">{{ $r['label'] }}</span></td>
                                <td class="min-w-36 px-4 py-3 align-top">@include('modules.file.partials.share-usage')</td>
                                <td class="px-4 py-3 align-top">@include('modules.file.partials.share-actions')</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-panel>
        @endif
    @endif
</div>
