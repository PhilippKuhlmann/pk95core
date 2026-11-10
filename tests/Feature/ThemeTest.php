<?php

namespace Tests\Feature;

use App\Models\User;

/**
 * Dark mode survives wire:navigate: both layouts set the theme through one function in
 * <head>, and app.js applies it again when Livewire swaps the page (and the <html> tag).
 */
class ThemeTest extends FeatureTestCase
{
    private function assertThemeInHead(string $html): void
    {
        $head = strstr($html, '</head>', true);
        $this->assertNotFalse($head, 'page has no <head>');
        $this->assertSame(1, substr_count($head, 'window.pk95ApplyTheme = function'), 'theme function defined once in <head>');
        $this->assertStringContainsString('window.pk95ApplyTheme();', $head);
        $this->assertStringContainsString("localStorage.getItem('color-theme')", $head);
        $this->assertStringContainsString('prefers-color-scheme: dark', $head);
        $this->assertSame(1, substr_count($html, 'window.pk95ApplyTheme = function'), 'no second theme script in <body>');
    }

    public function test_app_layout_sets_the_theme_in_head(): void
    {
        $this->signIn();

        $this->assertThemeInHead($this->get('/')->assertOk()->getContent());
        $this->assertThemeInHead($this->get('/profile')->assertOk()->getContent());
    }

    public function test_guest_layouts_set_the_theme_in_head(): void
    {
        $this->assertThemeInHead($this->get('/setup')->assertOk()->getContent());

        User::factory()->create(['username' => 'admin']);
        $this->assertThemeInHead($this->get('/login')->assertOk()->getContent());
    }

    public function test_app_js_applies_the_theme_again_after_wire_navigate(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        // on the page swap (no light flash) and once the navigation has finished
        $this->assertMatchesRegularExpression("/addEventListener\('livewire:navigating'.*?onSwap\?\.\(\(\) => window\.pk95ApplyTheme/s", $js);
        $this->assertMatchesRegularExpression("/addEventListener\('livewire:navigated'.*?window\.pk95ApplyTheme\?\.\(\);\s*initThemeToggle\(\);/s", $js);
    }
}
