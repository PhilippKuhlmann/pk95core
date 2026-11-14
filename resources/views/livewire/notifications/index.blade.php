@php
    $levels = ['info' => 'Alles (ab Info)', 'warning' => 'ab Warnung', 'critical' => 'nur Kritisch'];
    $secretHint = fn (string $key) => in_array($key, $form['has_secret'] ?? [], true) ? 'Gespeichert – leer lassen, um ihn zu behalten' : null;
@endphp
<div>
    <x-page-header title="Benachrichtigungen">
        <x-slot:sub>Alle Apps melden Ereignisse an Core. Hier legst du fest, welche Meldungen wohin gehen.</x-slot:sub>
        @if ($editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Kanal anlegen</x-input.button>
        @endif
    </x-page-header>

    @if ($editing !== null)
        <x-modal wire:key="channel-form" submit="save">
            <x-slot:title>{{ $editing === 'new' ? 'Neuer Kanal' : 'Kanal bearbeiten' }}</x-slot:title>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-field label="Name" for="c-name" feld="form.name" required>
                    <x-input.text id="c-name" feld="form.name" wire:model="form.name" class="mt-1 w-full" />
                </x-field>
                <x-field label="Art" for="c-type" feld="form.type">
                    <x-input.select id="c-type" wire:model.live="form.type" class="mt-1" :disabled="$editing !== 'new'">
                        @foreach ($typeLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </x-input.select>
                </x-field>
                <x-field label="Melden" for="c-sev" feld="form.min_severity">
                    <x-input.select id="c-sev" wire:model="form.min_severity" class="mt-1">
                        @foreach ($levels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </x-input.select>
                </x-field>
            </div>

            <x-form-section title="Verbindung" />
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-3">
                @switch($form['type'])
                    @case('telegram')
                        <x-field label="Bot-Token" for="c-bt" feld="form.bot_token" :hilfe="$secretHint('bot_token') ?? 'Von @BotFather'" class="md:col-span-2">
                            <x-input.text id="c-bt" type="password" feld="form.bot_token" wire:model="form.bot_token" class="mt-1 w-full font-mono" autocomplete="off" />
                        </x-field>
                        <x-field label="Chat-ID" for="c-chat" feld="form.chat_id">
                            <x-input.text id="c-chat" feld="form.chat_id" wire:model="form.chat_id" class="mt-1 w-full font-mono" />
                        </x-field>
                        @break
                    @case('email')
                        <x-field label="SMTP-Server" for="c-host" feld="form.host">
                            <x-input.text id="c-host" feld="form.host" wire:model="form.host" class="mt-1 w-full font-mono" />
                        </x-field>
                        <x-field label="Port" for="c-port" feld="form.port">
                            <x-input.text id="c-port" type="number" feld="form.port" wire:model="form.port" class="mt-1 w-full" />
                        </x-field>
                        <x-field label="Verschlüsselung" for="c-enc" feld="form.encryption">
                            <x-input.select id="c-enc" wire:model="form.encryption" class="mt-1">
                                <option value="tls">STARTTLS (587)</option><option value="ssl">SSL/TLS (465)</option><option value="none">keine</option>
                            </x-input.select>
                        </x-field>
                        <x-field label="Benutzer" for="c-user" feld="form.username">
                            <x-input.text id="c-user" feld="form.username" wire:model="form.username" class="mt-1 w-full" autocomplete="off" />
                        </x-field>
                        <x-field label="Passwort" for="c-pw" feld="form.password" :hilfe="$secretHint('password')">
                            <x-input.text id="c-pw" type="password" feld="form.password" wire:model="form.password" class="mt-1 w-full" autocomplete="new-password" />
                        </x-field>
                        <div></div>
                        <x-field label="Absender" for="c-from" feld="form.from">
                            <x-input.text id="c-from" type="email" feld="form.from" wire:model="form.from" class="mt-1 w-full" />
                        </x-field>
                        <x-field label="Empfänger" for="c-to" feld="form.to">
                            <x-input.text id="c-to" type="email" feld="form.to" wire:model="form.to" class="mt-1 w-full" />
                        </x-field>
                        @break
                    @case('ntfy')
                        <x-field label="Server" for="c-url" feld="form.url" hilfe="Leer = https://ntfy.sh">
                            <x-input.text id="c-url" feld="form.url" wire:model="form.url" class="mt-1 w-full font-mono" />
                        </x-field>
                        <x-field label="Topic" for="c-topic" feld="form.topic">
                            <x-input.text id="c-topic" feld="form.topic" wire:model="form.topic" class="mt-1 w-full font-mono" />
                        </x-field>
                        <x-field label="Zugangstoken" for="c-tok" feld="form.token" :hilfe="$secretHint('token') ?? 'Optional'">
                            <x-input.text id="c-tok" type="password" feld="form.token" wire:model="form.token" class="mt-1 w-full font-mono" autocomplete="off" />
                        </x-field>
                        @break
                    @case('webhook')
                        <x-field label="URL" for="c-hook" feld="form.url" hilfe="Core sendet das Ereignis als JSON (POST)" class="md:col-span-3">
                            <x-input.text id="c-hook" feld="form.url" wire:model="form.url" class="mt-1 w-full font-mono" />
                        </x-field>
                @endswitch
            </div>

            <x-form-section title="Filter" />
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <div class="text-sm font-medium text-gray-700 dark:text-gray-300">Apps</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Keine Auswahl = alle Apps</div>
                    <div class="mt-2 flex flex-col">
                        @foreach ($apps as $id => $name)
                            <label class="inline-flex items-center gap-2 py-0.5 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" value="{{ $id }}" wire:model="form.apps" class="rounded border-gray-300 text-cerulean-600 focus:ring-cerulean-500 dark:border-gray-600 dark:bg-gray-700">{{ $name }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <x-field label="Ereignistypen" for="c-types" feld="form.types" hilfe="Kommagetrennt, * als Platzhalter – z. B. monitor.*, core.update.*. Leer = alle">
                    <x-input.text id="c-types" feld="form.types" wire:model="form.types" class="mt-1 w-full font-mono" />
                </x-field>
                <x-input.checkbox label="Kanal aktiv" wire:model="form.enabled" />
            </div>
        </x-modal>
    @endif

    @if ($channels->isEmpty() && $editing === null)
        <x-emptystate message="Noch kein Kanal. Ohne Kanal landen Ereignisse nur im Ereignis-Log." />
    @elseif ($channels->isNotEmpty())
        <x-panel polster="keins" class="m-3 divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($channels as $channel)
                <div class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="ch-{{ $channel->id }}">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $channel->name }}</span>
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $typeLabels[$channel->type] ?? $channel->type }}</span>
                            @unless ($channel->enabled)
                                <span class="rounded bg-gray-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-700 dark:text-gray-400">Aus</span>
                            @endunless
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $levels[$channel->min_severity] ?? $channel->min_severity }}
                            · {{ $channel->apps ? collect($channel->apps)->map(fn ($a) => $apps[$a] ?? $a)->join(', ') : 'alle Apps' }}
                            @if ($channel->types) · {{ implode(', ', $channel->types) }} @endif
                        </div>
                        @if ($channel->last_error_at && (! $channel->last_success_at || $channel->last_error_at->gt($channel->last_success_at)))
                            <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">Fehler {{ $channel->last_error_at->diffForHumans() }}: {{ $channel->last_error }}</div>
                        @elseif ($channel->last_success_at)
                            <div class="mt-1 text-xs text-green-700 dark:text-green-400">Zuletzt zugestellt {{ $channel->last_success_at->diffForHumans() }}</div>
                        @endif
                    </div>
                    <span class="flex gap-1">
                        <x-input.button type="button" color="gray" size="sm" wire:click="test({{ $channel->id }})">Testen</x-input.button>
                        <x-input.symbolknopf wire:click="toggle({{ $channel->id }})" :titel="$channel->enabled ? 'Ausschalten' : 'Einschalten'"><x-icon :name="$channel->enabled ? 'stop' : 'play'" class="h-4 w-4" /></x-input.symbolknopf>
                        <x-input.symbolknopf wire:click="edit({{ $channel->id }})" titel="Bearbeiten"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                        <x-input.symbolknopf titel="Löschen"
                            x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from('Kanal „'.$channel->name.'“ löschen?') }}, button: 'Löschen', ok: () => $wire.delete({{ $channel->id }}) })">
                            <x-icon name="trash" class="h-4 w-4" />
                        </x-input.symbolknopf>
                    </span>
                </div>
            @endforeach
        </x-panel>
    @endif
</div>
