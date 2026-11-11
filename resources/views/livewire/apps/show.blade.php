<div @if ($installedApp->isTransitional() || $installedApp->step) wire:poll.3s="poll" @endif>
    <x-page-header :title="$installedApp->displayName()" :back="route('apps.index')" back-label="Apps">
        <x-slot:badge><x-status-pill :status="$installedApp->status" /></x-slot:badge>
        <x-slot:sub>{{ $definition?->summary }}</x-slot:sub>

        @if (! $installedApp->isTransitional() || $installedApp->status === 'starting')
            @if (in_array($installedApp->status, ['running', 'unhealthy', 'starting'], true))
                <x-input.button type="button" color="gray" wire:click="action('restart')"><x-icon name="refresh" class="h-4 w-4" sw="2" />Neustart</x-input.button>
                <x-input.button type="button" color="gray" wire:click="action('stop')"><x-icon name="stop" class="h-4 w-4" sw="2" />Stoppen</x-input.button>
            @elseif ($installedApp->status === 'stopped')
                <x-input.button type="button" wire:click="action('start')"><x-icon name="play" class="h-4 w-4" sw="2" />Starten</x-input.button>
            @endif
            <x-input.button type="button" color="gray" wire:click="action('reactivate')"
                title="Image neu laden und Container neu erstellen (Daten bleiben)"><x-icon name="box" class="h-4 w-4" sw="2" />Neu erstellen</x-input.button>
        @endif
    </x-page-header>

    <div class="m-3 space-y-3">
        @if ($installedApp->status === 'installing')
            <x-callout title="Wird aktiviert">{{ $installedApp->step ?? 'Eingeplant' }} …</x-callout>
        @elseif ($installedApp->step)
            <x-callout title="Läuft gerade">{{ $installedApp->step }} …</x-callout>
        @endif
        @if ($installedApp->error)
            <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert">{{ $installedApp->error }}</div>
        @endif
        @if ($manifest && $reason)
            <x-callout title="Seiten der App nicht verfügbar" ton="gelb">{{ $reason }}</x-callout>
        @endif

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <x-panel>
                <div class="mb-3 text-lg font-CoconPro text-gray-900 dark:text-gray-100">Container</div>
                <table class="w-full text-sm">
                    <x-kv label="Image" :value="$definition?->imageRef($installedApp->tag)" mono />
                    <x-kv label="Container" :value="$definition?->containerName()" mono />
                    <x-kv label="Netzwerk" :value="$definition?->networkMode === 'host' ? 'Host-Netz' : config('pk95.network')" />
                    <x-kv label="API-Adresse" :value="$definition?->baseUrl()" mono />
                    <x-kv label="Volumes">
                        @forelse ($definition?->volumes ?? [] as $v)
                            <div class="font-mono text-[13px]">{{ $definition->volumeName($v['name']) }} → {{ $v['target'] }}</div>
                        @empty
                            <span class="text-gray-500 dark:text-gray-400">—</span>
                        @endforelse
                    </x-kv>
                    <x-kv label="Zuletzt erreichbar" :value="$installedApp->last_seen_at?->diffForHumans()" />
                </table>
            </x-panel>

            <x-panel>
                <div class="mb-3 text-lg font-CoconPro text-gray-900 dark:text-gray-100">Manifest</div>
                @if ($manifest)
                    <table class="w-full text-sm">
                        <x-kv label="Version" :value="$manifest->version" />
                        <x-kv label="Protokoll" :value="'v'.$manifest->protocol" />
                        <x-kv label="Seiten">
                            @forelse ($manifest->menu as $entry)
                                @php($available = $pages->contains('key', $entry['key']))
                                <div class="flex items-center gap-1.5">
                                    <x-icon :name="$entry['icon']" class="h-4 w-4 text-gray-400" />
                                    @if ($available)
                                        <a href="{{ route('apps.page', [$installedApp, $entry['key']]) }}" class="text-cerulean-600 hover:underline dark:text-cerulean-400">{{ $entry['label'] }}</a>
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">{{ $entry['label'] }}</span>
                                    @endif
                                </div>
                            @empty
                                <span class="text-gray-500 dark:text-gray-400">—</span>
                            @endforelse
                        </x-kv>
                        <x-kv label="Fähigkeiten" :value="implode(', ', $manifest->capabilities)" />
                        <x-kv label="Ereignisse" :value="implode(', ', $manifest->events)" />
                    </table>
                @else
                    <div class="text-sm text-gray-500 dark:text-gray-400">Noch kein Manifest – die App hat sich bisher nicht gemeldet.</div>
                @endif
            </x-panel>
        </div>

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <x-panel>
                <div class="mb-3 text-lg font-CoconPro text-gray-900 dark:text-gray-100">Updates</div>
                @if ($installedApp->available_version)
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-cerulean-200 bg-cerulean-50 px-3 py-2 text-sm text-cerulean-900 dark:border-cerulean-800 dark:bg-cerulean-900/20 dark:text-cerulean-200">
                        <span>Update verfügbar: <span class="font-mono">{{ $installedApp->available_version }}</span></span>
                        @unless ($installedApp->step || $installedApp->isTransitional())
                            <x-input.button type="button" size="sm"
                                x-on:click="$store.confirm.ask({ title: 'Jetzt aktualisieren?', text: 'Core sichert die Daten, lädt das neue Image und startet die App neu. Klappt die Funktionsprüfung nicht, kehrt Core zur vorigen Version zurück.', button: 'Aktualisieren', tone: 'blue', ok: () => $wire.runUpdate() })">
                                Jetzt aktualisieren
                            </x-input.button>
                        @endunless
                    </div>
                @endif
                <x-field label="Update-Regel" for="u-policy" hilfe="Automatische Updates laufen im Wartungsfenster (Einstellungen).">
                    <x-input.select id="u-policy" wire:model.live="updatePolicy" class="mt-1">
                        @foreach ($policies as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </x-input.select>
                </x-field>
                <div class="mt-3 flex items-center justify-between gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <span>Zuletzt geprüft: {{ $installedApp->update_checked_at?->diffForHumans() ?? 'noch nie' }}</span>
                    <x-input.button type="button" color="gray" size="sm" wire:click="checkUpdates" wire:loading.attr="disabled">Jetzt prüfen</x-input.button>
                </div>
            </x-panel>

            <x-panel>
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">Sicherungen</div>
                    @if ($definition?->volumes && ! $installedApp->step && ! $installedApp->isTransitional())
                        <x-input.button type="button" color="gray" size="sm" wire:click="backupNow">Jetzt sichern</x-input.button>
                    @endif
                </div>
                @if (! $definition?->volumes)
                    <div class="text-sm text-gray-500 dark:text-gray-400">Diese App speichert keine Daten.</div>
                @else
                    <x-input.checkbox label="Tägliche Sicherung im Wartungsfenster" wire:model.live="autoBackup" />
                    @if ($backups->isEmpty())
                        <div class="mt-3 text-sm text-gray-500 dark:text-gray-400">Noch keine Sicherung.</div>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                            @foreach ($backups as $backup)
                                <li class="flex flex-wrap items-center gap-2 py-1.5" wire:key="bk-{{ $backup->id }}">
                                    <span class="w-28 font-mono text-xs text-gray-500 dark:text-gray-400">{{ \App\Http\Support\Format::local($backup->created_at)?->format('d.m. H:i') }}</span>
                                    <span class="flex-1 text-gray-700 dark:text-gray-200">
                                        {{ \App\Models\Backup::REASONS[$backup->reason] ?? $backup->reason }}
                                        @if ($backup->version) · {{ $backup->version }} @endif
                                        @if ($backup->status === 'done')
                                            · {{ \App\Services\Backups\BackupService::humanSize($backup->size) }}
                                        @elseif ($backup->status === 'failed')
                                            · <span class="text-rose-600 dark:text-rose-400" title="{{ $backup->error }}">fehlgeschlagen</span>
                                        @else
                                            · läuft …
                                        @endif
                                    </span>
                                    @if ($backup->status === 'done' && ! $installedApp->step)
                                        <x-input.symbolknopf titel="Wiederherstellen"
                                            x-on:click="$store.confirm.ask({ title: 'Sicherung wiederherstellen?', text: {{ \Illuminate\Support\Js::from('Die aktuellen Daten von '.$installedApp->displayName().' werden durch den Stand vom '.\App\Http\Support\Format::dt($backup->created_at).' ersetzt.') }}, button: 'Wiederherstellen', ok: () => $wire.restore({{ $backup->id }}) })">
                                            <x-icon name="refresh" class="h-4 w-4" />
                                        </x-input.symbolknopf>
                                    @endif
                                    <x-input.symbolknopf titel="Löschen"
                                        x-on:click="$store.confirm.ask({ title: 'Sicherung löschen?', button: 'Löschen', ok: () => $wire.deleteBackup({{ $backup->id }}) })">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </x-input.symbolknopf>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </x-panel>
        </div>

        <x-panel>
            <div class="mb-3 text-lg font-CoconPro text-gray-900 dark:text-gray-100">Verlauf</div>
            @if ($history->isEmpty())
                <div class="text-sm text-gray-500 dark:text-gray-400">Noch keine Einträge.</div>
            @else
                <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach ($history as $entry)
                        <li class="flex gap-3 py-1.5">
                            <span class="shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400">{{ \App\Http\Support\Format::local($entry->created_at)?->format('d.m. H:i:s') }}</span>
                            <span @class([
                                'text-red-600 dark:text-red-400' => $entry->level === 'error',
                                'text-green-700 dark:text-green-400' => $entry->level === 'success',
                                'text-gray-700 dark:text-gray-200' => ! in_array($entry->level, ['error', 'success'], true),
                            ])>{{ $entry->message }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-panel>

        <x-panel>
            <div class="mb-3 flex items-center justify-between gap-3">
                <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">Container-Log</div>
                <x-input.button type="button" color="gray" size="sm" wire:click="$toggle('showLogs')">
                    {{ $showLogs ? 'Ausblenden' : 'Anzeigen' }}
                </x-input.button>
            </div>
            @if ($showLogs)
                @if ($logError)
                    <div class="text-sm text-red-600 dark:text-red-400">{{ $logError }}</div>
                @else
                    <pre class="max-h-[32rem] overflow-auto rounded-lg bg-gray-950 p-3 font-mono text-xs leading-relaxed text-gray-200">@foreach ($logs as $l)<span @class(['text-rose-300' => $l['stream'] === 'stderr'])>{{ $l['line'] }}</span>
@endforeach</pre>
                @endif
            @endif
        </x-panel>

        {{-- removing is set apart from the everyday actions in the header --}}
        @if (! $installedApp->isTransitional() || $installedApp->status === 'starting')
            <x-panel class="border-red-200 dark:border-red-900/60" data-danger-zone>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">Gefahrenzone</div>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">App entfernen: Der Container wird gelöscht, die Daten (Volumes) bleiben erhalten. Beim erneuten Aktivieren sind sie wieder da.</p>
                    </div>
                    <x-input.button type="button" color="gray" class="text-red-700! dark:text-red-300!"
                        x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from($installedApp->displayName().' entfernen?') }}, text: 'Der Container wird entfernt, die Daten (Volumes) bleiben erhalten.', button: 'Entfernen', ok: () => $wire.remove(true) })">
                        <x-icon name="trash" class="h-4 w-4" sw="2" />Entfernen
                    </x-input.button>
                </div>
            </x-panel>
        @endif
    </div>
</div>
