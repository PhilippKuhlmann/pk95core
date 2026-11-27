<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;

use function Livewire\on;

abstract class TestCase extends BaseTestCase
{
    /** Pages and tiles render their skeleton first (see withSkeletons()). */
    private bool $skeletons = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages load deferred behind a skeleton (App\Modules\Concerns\LoadsLazily). A test sees
        // the loaded page, unless it asks for the skeleton. Livewire forgets the switch after
        // every request (flush-state), so it is set again right after.
        SupportLazyLoading::disableWhileTesting();
        on('flush-state', function () {
            if (! $this->skeletons) {
                SupportLazyLoading::disableWhileTesting();
            }
        });
    }

    /** The first request of a page answers with its skeleton, as in the browser. */
    protected function withSkeletons(): static
    {
        $this->skeletons = true;
        SupportLazyLoading::$disableWhileTesting = false;

        return $this;
    }
}
