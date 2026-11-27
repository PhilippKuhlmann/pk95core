<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Plays the browser for a deferred page: the first request answers with the skeleton, then the
 * Livewire request that loads the page (__lazyLoad) - with or without a Referer header.
 */
trait LoadsDeferredPages
{
    /**
     * @return string the HTML of the loaded page component
     */
    protected function loadDeferred(string $url, ?string $referer = null): string
    {
        $this->withSkeletons();
        $first = $this->get($url)->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div[^>]*wire:snapshot="([^"]+)"[^>]*data-skeleton/', $first, $s), 'skeleton component');
        $this->assertSame(1, preg_match('/\$wire\.__lazyLoad\(&#039;([^&]+)&#039;\)/', $first, $p), 'lazy load call');

        $headers = ['X-Livewire' => '1'];
        if ($referer !== null) {
            $headers['Referer'] = $referer;
        }
        $response = $this->withHeaders($headers)->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($s[1], ENT_QUOTES),
                'updates' => [],
                'calls' => [['path' => '', 'method' => '__lazyLoad', 'params' => [html_entity_decode($p[1], ENT_QUOTES)]]],
            ]],
        ]);
        $this->flushHeaders();

        return $this->deferredHtml($response);
    }

    private function deferredHtml(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('components.0.effects.html');
    }
}
