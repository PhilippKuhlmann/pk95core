{{-- ProxApps start page (port of proxapps livewire/dashboard). --}}
<div class="p-3 sm:p-5">
    @if ($bridgeError)
        <x-callout title="ProxApps antwortet nicht" ton="gelb" class="mb-5">{{ $bridgeError }}</x-callout>
    @else
        @php $active = ($counts['queued'] ?? 0) + ($counts['running'] ?? 0); @endphp
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <div class="text-3xl font-CoconPro text-gray-900 dark:text-gray-100">ProxApps</div>
            <x-input.linkbutton :href="$this->pageUrl('apps')">
                <x-icon name="rocket" class="w-4 h-4" sw="2" />App ausrollen
            </x-input.linkbutton>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 mb-6">
            <x-stat-tile :href="$this->pageUrl('connections')" icon="server" :value="$connections->count()" label="Verbindungen" />
            <x-stat-tile :href="$this->pageUrl('apps')" icon="apps" :value="count($apps)" label="Apps im Katalog" />
            <x-stat-tile :href="$this->pageUrl('deployments')" icon="check" ton="gruen" :value="$counts['success'] ?? 0" label="Erfolgreich ausgerollt" />
            <x-stat-tile :href="$this->pageUrl('deployments')" icon="clock" :value="$active" label="Laufend" />
            <x-stat-tile :href="$this->pageUrl('deployments')" icon="warn" ton="rot" :value="$counts['failed'] ?? 0" label="Fehlgeschlagen" />
        </div>

        @if ($connections->isEmpty())
            <x-callout title="Noch keine Proxmox-Verbindung" class="mb-5">
                Verbinde zuerst deinen Proxmox-Server oder -Cluster über ein API-Token. Danach kannst du Apps mit wenigen Klicks ausrollen.
                <x-slot:aktionen>
                    <x-input.linkbutton :href="$this->pageUrl('connection-form')">
                        <x-icon name="plus" class="w-4 h-4" sw="2.2" />Verbindung anlegen
                    </x-input.linkbutton>
                </x-slot:aktionen>
            </x-callout>
        @elseif ($profileCount === 0)
            <x-callout title="Tipp: Netzwerkprofil anlegen" class="mb-5">
                Mit einem Netzwerkprofil (Bridge, VLAN, Netz, Gateway, IP-Pool, VMID-Muster) schlägt ProxApps freie IPs und passende VMIDs automatisch vor.
                <x-slot:aktionen>
                    <x-input.linkbutton :href="$this->pageUrl('profile-form')" color="gray">Profil anlegen</x-input.linkbutton>
                </x-slot:aktionen>
            </x-callout>
        @endif

        @if ($connections->isNotEmpty())
            <div class="mb-6">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Proxmox-Verbindungen</div>
                <div class="flex flex-col gap-2">
                    @foreach ($connections as $connection)
                        @livewire(\App\Modules\ProxApps\Pages\ConnectionStatus::class, ['installedApp' => $installedApp, 'connectionId' => $connection->id], key('conn-'.$connection->id))
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <x-dashboard-tile title="Letzte Deployments">
                <x-slot:aktion>
                    <a href="{{ $this->pageUrl('deployments') }}" class="text-sm text-cerulean-600 hover:text-cerulean-700 dark:text-cerulean-400">Alle anzeigen</a>
                </x-slot:aktion>
                @if ($recent->isEmpty())
                    <div class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Noch nichts ausgerollt.</div>
                @else
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($recent as $d)
                            <a href="{{ $this->pageUrl('deployment', ['id' => $d->id]) }}"
                                class="flex items-center justify-between gap-3 py-2.5 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                <span class="min-w-0">
                                    <span class="block truncate text-gray-800 dark:text-gray-100">{{ $d->hostname }} <span class="text-gray-500 dark:text-gray-400">· {{ $d->app_name }}</span></span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                        <span class="font-mono">CT {{ $d->vmid }}</span>{{ $d->ip ? ' · '.$d->ip : '' }}@if (\App\Models\AppLink::forProxApps((int) $d->id)?->shownDomain()) · <x-proxapps.domain :deployment-id="$d->id" compact />@endif · {{ $d->node }} · {{ \App\Http\Support\Format::dt($d->created_at) }}
                                    </span>
                                </span>
                                <x-status-pill :status="$d->status" class="shrink-0" />
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-dashboard-tile>

            <x-dashboard-tile title="App-Katalog">
                <x-slot:aktion>
                    <a href="{{ $this->pageUrl('apps') }}" class="text-sm text-cerulean-600 hover:text-cerulean-700 dark:text-cerulean-400">Katalog öffnen</a>
                </x-slot:aktion>
                @if (! count($apps))
                    <div class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Der Katalog ist leer.</div>
                @else
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($apps as $app)
                            <a href="{{ $this->pageUrl('deploy', ['app' => $app->id]) }}"
                                class="flex items-center gap-3 py-2.5 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                <x-app-icon :app="$app" :installed="$installedApp" class="h-9 w-9" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-gray-800 dark:text-gray-100">{{ $app->name }}</span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $app->summary }}</span>
                                </span>
                                <span class="shrink-0 text-sm font-medium text-cerulean-600 dark:text-cerulean-400">Ausrollen</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-dashboard-tile>
        </div>
    @endif
</div>
