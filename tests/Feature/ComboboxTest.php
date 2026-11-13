<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;

/**
 * x-input.combobox: the searchable dropdown (Alpine "combobox" in app.js).
 */
class ComboboxTest extends FeatureTestCase
{
    private function render(string $blade, array $data = []): string
    {
        return Blade::render($blade, $data);
    }

    public function test_it_renders_an_aria_combobox_with_its_options_as_json(): void
    {
        $html = $this->render('<x-input.combobox wire:model.live="host" label="Host" :options="$o" placeholder="Alle Hosts" reset="Alle Hosts" class="w-56" />', [
            'o' => ['a.example.de' => 'a.example.de', 'b.example.de' => 'b.example.de'],
        ]);

        // the binding sits on the Alpine root with x-modelable (wire:model / .live / #[Url] work as on a select)
        $this->assertMatchesRegularExpression('/<div wire:model\.live="host" class="relative w-56"\s+x-data="combobox\(/', $html);
        $this->assertStringContainsString('x-modelable="value"', $html);
        $this->assertStringContainsString('data-options="[{&quot;value&quot;:&quot;a.example.de&quot;,&quot;label&quot;:&quot;a.example.de&quot;}', $html);
        // ARIA: trigger, listbox, the reset entry, the empty state
        $this->assertMatchesRegularExpression('/role="combobox" aria-haspopup="listbox" aria-controls="cb-[0-9a-f]{8}-list" aria-label="Host"/', $html);
        $this->assertStringContainsString('role="listbox" aria-label="Host"', $html);
        $this->assertStringContainsString('role="option"', $html);
        $this->assertStringContainsString('>Alle Hosts</button>', $html);
        $this->assertStringContainsString('Keine Treffer', $html);
        // trigger and menu are left alone by Livewire's morph (polling pages keep it open)
        $this->assertStringContainsString('<div wire:ignore>', $html);
        // two options: no search field by default
        $this->assertStringNotContainsString('x-ref="search"', $html);
    }

    public function test_options_with_hints_groups_multiple_and_search(): void
    {
        $html = $this->render('<x-input.combobox wire:model.live="hidden" label="Clients" :options="$o" :multiple="true" :search="true" />', [
            'o' => [
                ['value' => 'Uptime-Monitor', 'label' => 'Uptime-Monitor', 'hint' => '4.201', 'group' => 'Häufig'],
                ['value' => 'Firefox', 'label' => 'Firefox', 'hint' => '', 'group' => 'Häufig'],
            ],
        ]);

        $this->assertStringContainsString('&quot;hint&quot;:&quot;4.201&quot;,&quot;group&quot;:&quot;Häufig&quot;', $html);
        $this->assertStringContainsString('{&quot;value&quot;:&quot;Firefox&quot;,&quot;label&quot;:&quot;Firefox&quot;,&quot;group&quot;:&quot;Häufig&quot;}', $html, 'an empty hint is left out');
        $this->assertStringContainsString('aria-multiselectable="true"', $html);
        $this->assertStringContainsString('\u0022multiple\u0022:true', $html);
        $this->assertStringContainsString('x-ref="search"', $html);
        $this->assertStringContainsString('aria-autocomplete="list"', $html);
    }

    public function test_the_id_is_stable_across_renders(): void
    {
        $blade = '<x-input.combobox wire:model.live="zone" label="Zone" :options="[]" />';
        preg_match('/aria-controls="(cb-[0-9a-f]{8})-list"/', $this->render($blade), $a);
        preg_match('/aria-controls="(cb-[0-9a-f]{8})-list"/', $this->render($blade), $b);

        $this->assertSame($a[1], $b[1], 'Livewire morphs the same element');
    }
}
