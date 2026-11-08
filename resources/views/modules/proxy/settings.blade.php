@php
    use App\Modules\Proxy\Fmt;
    $card = 'font-DINPro-bold text-gray-900 dark:text-gray-100';
@endphp

<div>
    <x-page-header title="Einstellungen" />

    @if ($loadError)
        <x-callout title="PK95 Proxy antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    <div class="grid grid-cols-1 gap-0 lg:grid-cols-2">
        <x-panel class="m-3">
            <div class="{{ $card }}">Standardseite</div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Diese Antwort erhält jeder, der Port 80 oder 443 mit einem Namen oder einer IP-Adresse aufruft, für die kein Host eingerichtet ist.</p>
            <form wire:submit="saveDefault" class="mt-3 flex flex-col gap-4">
                <x-field label="Antwort" for="d-action" :hilfe="$defaultAction === 'drop'
                    ? 'Auf Port 80 wird die Verbindung getrennt, auf Port 443 scheitert schon der Verbindungsaufbau. Scanner sehen so möglichst wenig. Anfragen auf Port 443 erscheinen dann nicht in den Zugriffen.'
                    : 'Auf Port 443 zeigt der Browser zuerst eine Zertifikatswarnung, weil es für unbekannte Namen kein gültiges Zertifikat geben kann. Auf Port 80 erscheint die Antwort direkt.'">
                    <x-input.select id="d-action" wire:model.live="defaultAction" class="mt-1">
                        <option value="404">Fehlerseite 404</option>
                        <option value="page">Eigene Seite</option>
                        <option value="redirect">Weiterleiten</option>
                        <option value="drop">Verbindung schließen</option>
                    </x-input.select>
                </x-field>
                @if ($defaultAction === 'page')
                    <x-field label="HTML der Seite" for="d-html" hilfe="Höchstens 64 KB. Bilder und Schriften müssen eingebettet oder von außen geladen werden.">
                        <x-input.textarea id="d-html" rows="8" wire:model="defaultHtml" placeholder="<h1>Willkommen</h1>" class="mt-1" />
                    </x-field>
                @elseif ($defaultAction === 'redirect')
                    <x-field label="Ziel der Weiterleitung" for="d-url">
                        <x-input.text id="d-url" wire:model="defaultRedirect" placeholder="https://www.example.de/" class="mt-1 w-full font-mono" />
                    </x-field>
                @endif
                <div><x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button></div>
            </form>
        </x-panel>

        <x-panel class="m-3">
            <div class="{{ $card }}">Allgemein</div>
            <form wire:submit="saveGeneral" class="mt-3 flex flex-col gap-4">
                <x-field label="E-Mail für Let's Encrypt" for="s-email" hilfe="Optional. Darüber meldet sich die Zertifizierungsstelle bei Problemen.">
                    <x-input.text id="s-email" type="email" wire:model="acmeEmail" class="mt-1 w-full" />
                </x-field>
                <x-field label="Zugriffsdaten aufbewahren (Tage)" for="s-ret" hilfe="Die Daten enthalten IP-Adressen. Ältere Einträge werden stündlich gelöscht.">
                    <x-input.text id="s-ret" type="number" min="1" max="365" wire:model="retentionDays" class="mt-1 w-32" />
                </x-field>
                <x-field label="Vertrauenswürdige Proxys" for="s-proxies" hilfe="Nur nötig hinter Cloudflare oder einem anderen Proxy. Dann wird die echte Client-IP aus X-Forwarded-For übernommen.">
                    <x-input.textarea id="s-proxies" rows="2" wire:model="trustedProxies" placeholder="Leer lassen, wenn kein weiterer Proxy davor sitzt" class="mt-1" />
                </x-field>
                <div><x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button></div>
            </form>
        </x-panel>

        <x-panel class="m-3">
            <div class="{{ $card }}">System</div>
            @if ($status)
                <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                    <dt class="text-gray-500">Caddy</dt>
                    <dd class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot($status['caddy_ok'] ? 'good' : 'bad') }}"></span>{{ $status['caddy_ok'] ? 'Läuft' : 'Nicht erreichbar' }}</dd>
                    <dt class="text-gray-500">Länder-Erkennung</dt>
                    <dd class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot($status['geoip'] ? 'good' : 'warn') }}"></span>{{ $status['geoip'] ? 'Aktiv' : 'Datenbank fehlt' }}</dd>
                    <dt class="text-gray-500">Gespeicherte Zugriffe</dt><dd>{{ Fmt::num($status['rows']) }}</dd>
                    <dt class="text-gray-500">Ältester Eintrag</dt><dd>{{ $status['oldest_ts'] ? Fmt::date($status['oldest_ts']) : '–' }}</dd>
                    <dt class="text-gray-500">Datenbankgröße</dt><dd>{{ Fmt::bytes($status['db_bytes']) }}</dd>
                </dl>
            @else
                <p class="mt-2 text-sm text-gray-500">Nicht verfügbar.</p>
            @endif
        </x-panel>

        <x-panel class="m-3">
            <div class="{{ $card }}">Benachrichtigungen und Anmeldung</div>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Unter PK95 Core verschickt der Proxy keine eigenen Nachrichten. Ausfälle, neue Zertifikate und Schwachstellen kommen als Ereignisse in Core an und gehen von dort an deine Kanäle.
                Das eigene Login des Panels ist abgeschaltet, Core greift mit seinem Token zu.
            </p>
            <a href="{{ route('notifications.index') }}" wire:navigate class="mt-3 inline-block text-sm text-cerulean-700 hover:underline dark:text-cerulean-300">Benachrichtigungen in Core</a>
        </x-panel>
    </div>
</div>
