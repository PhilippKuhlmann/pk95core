<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDocker;
use Tests\Fixtures\DemoModule;
use Tests\TestCase;

abstract class FeatureTestCase extends TestCase
{
    use FakesDocker;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'pk95.catalog_dir' => base_path('tests/Fixtures/catalog'),
            'pk95.modules' => ['demo' => DemoModule::class],
            'pk95.health_timeout' => 10,
            'pk95.poll_interval' => 2,
        ]);
        $this->withoutVite();
        Sleep::fake(syncWithCarbon: true);
        $this->fakeDocker();
    }

    protected function signIn(): User
    {
        $user = User::factory()->create(['username' => 'admin']);
        $this->actingAs($user);

        return $user;
    }
}
