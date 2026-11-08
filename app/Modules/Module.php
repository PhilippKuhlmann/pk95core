<?php

namespace App\Modules;

use App\Services\Apps\Manifest;
use Livewire\Component;

/**
 * A Core module renders one app's pages inside Core. It maps the menu keys the app
 * announces in its manifest to Livewire page components, and says which app versions
 * it understands - pages of an app version outside that range stay locked.
 *
 * Page components receive the InstalledApp as public property $installedApp and talk to
 * the app through $this->installedApp->client().
 */
abstract class Module
{
    /**
     * @return array<string, class-string<Component>> menu key → page component
     */
    abstract public function pages(): array;

    /**
     * Pages reachable by link but without a sidebar entry (forms, detail pages).
     *
     * @return array<string, class-string<Component>>
     */
    public function subPages(): array
    {
        return [];
    }

    /** Lowest supported app version (inclusive), null = any. */
    public function minVersion(): ?string
    {
        return null;
    }

    /** First unsupported app version (exclusive), null = open-ended. */
    public function maxVersion(): ?string
    {
        return null;
    }

    public function supports(Manifest $manifest): bool
    {
        if (! in_array($manifest->protocol, (array) config('pk95.protocols', [1]), true)) {
            return false;
        }
        $version = ltrim($manifest->version, 'v');
        if (($min = $this->minVersion()) !== null && version_compare($version, $min, '<')) {
            return false;
        }
        if (($max = $this->maxVersion()) !== null && version_compare($version, $max, '>=')) {
            return false;
        }

        return true;
    }
}
