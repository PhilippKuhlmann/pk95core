<?php

namespace Tests\Fixtures;

use App\Modules\Module;

class DemoModule extends Module
{
    public function pages(): array
    {
        return ['items' => DemoItemsPage::class];
    }

    public function minVersion(): ?string
    {
        return '1.0';
    }

    public function maxVersion(): ?string
    {
        return '2.0';
    }
}
