<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;

/**
 * Hover texts use the own tooltip layer (data-tip, app.js), not the native title tooltip,
 * which only shows after about a second and would appear twice next to the own one.
 */
class TooltipTest extends FeatureTestCase
{
    /** Components whose `title` is a prop (heading), not an HTML attribute. */
    private const TITLE_PROPS = ['x-page-header', 'x-callout', 'x-form-section', 'x-dashboard-tile', 'x-layouts::app', 'x-layouts::guest', 'x-layouts.app'];

    public function test_no_view_uses_a_native_title_tooltip(): void
    {
        $found = [];
        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            $s = $file->getContents();
            preg_match_all('/\s:?title="/', $s, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [, $pos]) {
                if (! preg_match('/.*<([a-zA-Z][\w.:-]*)/s', substr($s, 0, $pos), $tag)) {
                    continue;
                }
                // an iframe needs its title as accessible name
                if ($tag[1] === 'iframe' || in_array($tag[1], self::TITLE_PROPS, true)) {
                    continue;
                }
                $found[] = $file->getRelativePathname().':'.(substr_count(substr($s, 0, $pos), "\n") + 1).' <'.$tag[1].'>';
            }
            // SVG <title> shows the native tooltip too; only the document title is allowed
            if (preg_match_all('/<title>/', $s) > (str_contains($s, '<head>') ? 1 : 0)) {
                $found[] = $file->getRelativePathname().' <title>';
            }
        }

        $this->assertSame([], $found, 'use data-tip="…" instead of title');
    }

    public function test_icon_buttons_carry_the_tip_and_keep_their_accessible_name(): void
    {
        $html = Blade::render('<x-input.symbolknopf titel="Wartung starten" wire:click="x"><x-icon name="pause" class="h-4 w-4" /></x-input.symbolknopf>');

        $this->assertStringContainsString('data-tip="Wartung starten" aria-label="Wartung starten"', $html);
        $this->assertStringNotContainsString(' title=', $html);
    }

    public function test_status_signs_have_a_name_and_a_tip_but_no_svg_title(): void
    {
        $on = Blade::render('<x-statusicon :value="true" />');
        $unknown = Blade::render('<x-statusicon :value="null" />');

        $this->assertStringContainsString('role="img" aria-label="Aktiv" data-tip="Aktiv"', $on);
        $this->assertStringNotContainsString('<title>', $on);
        $this->assertStringContainsString('role="img" aria-label="unbekannt" data-tip="unbekannt"', $unknown);
    }
}
