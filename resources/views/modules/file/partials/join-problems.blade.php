{{--
    PK95 File → Dateiserver: what went wrong before the file server got the join request (PK95 AD,
    DNS), "Neu verbinden" when the computer account exists, and the password fallback after a
    failed offline join. Shares the variables of the server page.
--}}
@if ($joinError)
    <x-callout title="Der Beitritt ist nicht gestartet" ton="gelb" class="m-3" data-join-error>
        {{ $joinError }}
        @if ($offerReset)
            <span class="mt-1 block">„Neu verbinden“ setzt das Konto in PK95 AD zurück (neues Kennwort). Ein anderer Rechner mit diesem Namen verliert dabei seine Verbindung zur Domäne.</span>
        @endif
        @if ($offerReset)
            <x-slot:aktionen>
                @if ($state === 'ready')
                    <x-input.button type="button" size="sm" data-reset-join
                        x-on:click="$store.confirm.ask({ title: 'Computerkonto zurücksetzen?', text: 'PK95 AD vergibt dem vorhandenen Computerkonto ein neues Kennwort und der Dateiserver tritt damit neu bei.', button: 'Neu verbinden', tone: 'blue', ok: () => $wire.rejoin() })">
                        Neu verbinden
                    </x-input.button>
                @else
                    <x-input.button type="button" size="sm" data-reset-join
                        x-on:click="$store.confirm.ask({ title: 'Computerkonto zurücksetzen?', text: 'PK95 AD vergibt dem vorhandenen Computerkonto ein neues Kennwort und der Dateiserver tritt damit bei.', button: 'Neu verbinden', tone: 'blue', ok: () => $wire.startSetup(true) })">
                        Neu verbinden
                    </x-input.button>
                @endif
            </x-slot:aktionen>
        @endif
    </x-callout>
@endif

@if ($odjFailed)
    {{-- fallback: one-time admin password, sent once and emptied in the same request --}}
    <x-panel class="m-3 max-w-3xl" data-password-join>
        <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Mit Admin-Kennwort beitreten</div>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Klappt der Beitritt ohne Kennwort nicht, kann der Dateiserver mit dem Konto eines Domänen-Admins beitreten.
            Das Kennwort geht nur dieses eine Mal an PK95 File und wird weder von PK95 Core noch von PK95 File gespeichert.
        </p>
        <form wire:submit="joinWithPassword" class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2" autocomplete="off">
            <x-field label="Benutzer" for="fj-user" feld="joinUser">
                <x-input.text id="fj-user" feld="joinUser" wire:model="joinUser" autocomplete="off" class="mt-1 w-full" />
            </x-field>
            <x-field label="Kennwort" for="fj-pw" feld="joinPassword">
                <x-input.text id="fj-pw" type="password" feld="joinPassword" wire:model="joinPassword" autocomplete="off" class="mt-1 w-full" />
            </x-field>
            <div class="flex justify-end sm:col-span-2">
                <x-input.button type="submit" color="gray" wire:loading.attr="disabled" wire:target="joinWithPassword">Mit Kennwort beitreten</x-input.button>
            </div>
        </form>
    </x-panel>
@endif
