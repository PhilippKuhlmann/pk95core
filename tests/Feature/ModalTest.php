<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;

/**
 * x-modal: forms open as a dialog over the page (Alpine "modal" in app.js), not as a panel
 * at the top of the page that one does not see after scrolling down.
 */
class ModalTest extends FeatureTestCase
{
    public function test_it_renders_an_aria_dialog_with_a_form_and_a_fixed_footer(): void
    {
        $html = Blade::render('<x-modal wire:key="host-form" submit="save"><x-slot:title>Host bearbeiten</x-slot:title><input id="f-name"></x-modal>');

        // the page's key on the root, the Alpine part with the method that closes
        $this->assertMatchesRegularExpression('/<div class="fixed inset-0 z-\[55\] [^"]*" wire:key="host-form"\s+x-data="modal\(/', $html);
        $this->assertStringContainsString(str_replace('"', chr(92).'u0022', '{"close":"cancel"}'), $html, 'JSON.parse of @js');
        // dialog named by its heading
        preg_match('/aria-labelledby="(modal-[0-9a-f]{8})-title"/', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString('role="dialog" aria-modal="true"', $html);
        $this->assertStringContainsString('<h2 id="'.$m[1].'-title"', $html);
        $this->assertStringContainsString('>Host bearbeiten</h2>', $html);
        // Esc, Tab and the backdrop are handled; close with X and Abbrechen, submit the form
        $this->assertStringContainsString('x-on:keydown.escape.prevent.stop="requestClose()"', $html);
        $this->assertStringContainsString('x-on:keydown.tab="trap($event)"', $html);
        $this->assertStringContainsString('aria-label="Schließen"', $html);
        $this->assertStringContainsString('<form wire:submit="save"', $html);
        $this->assertMatchesRegularExpression('/x-on:click="requestClose\(\)"[^>]*>\s*Abbrechen/', $html);
        $this->assertMatchesRegularExpression('/type="submit"[^>]*>\s*Speichern/', $html);
        // body scrolls, head and foot stay; a full screen sheet on phones
        $this->assertStringContainsString('min-h-0 flex-1 overflow-y-auto', $html);
        $this->assertStringContainsString('h-dvh w-full', $html);
        // no transform on the dialog: comboboxes (position: fixed) inside must not be clipped
        $this->assertStringNotContainsString('scale-', $html);
        $this->assertStringNotContainsString('translate-', $html);
    }

    public function test_the_id_is_stable_and_the_save_label_can_change(): void
    {
        $blade = '<x-modal wire:key="zone-form" submit="saveZone" save-label="Anlegen" title="Zone">x</x-modal>';
        preg_match('/aria-labelledby="([^"]+)"/', $a = Blade::render($blade), $x);
        preg_match('/aria-labelledby="([^"]+)"/', Blade::render($blade), $y);

        $this->assertSame($x[1], $y[1], 'Livewire morphs the same dialog');
        $this->assertStringContainsString('<form wire:submit="saveZone"', $a);
        $this->assertMatchesRegularExpression('/type="submit"[^>]*>\s*Anlegen/', $a);
        $this->assertStringContainsString('>Zone</h2>', $a);
    }

    public function test_no_page_shows_its_form_as_a_panel_above_the_list(): void
    {
        $found = [];
        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            if (preg_match('/<x-panel[^>]*wire:key="[\w-]*form"[^>]*>\s*<form wire:submit/', $file->getContents())) {
                $found[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $found, 'open add/edit forms with <x-modal>');
    }
}
