<?php

namespace Database\Factories;

use App\Models\InstalledApp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InstalledApp>
 */
class InstalledAppFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_id' => 'demo',
            'status' => InstalledApp::RUNNING,
            'tag' => '1.0.0',
            'container_id' => Str::random(12),
            'token' => Str::random(48),
            'manifest' => [
                'id' => 'demo', 'name' => 'Demo-App', 'version' => '1.2.0', 'protocol' => 1, 'icon' => 'box',
                'menu' => [['key' => 'items', 'label' => 'Einträge', 'icon' => 'list']],
                'capabilities' => [], 'events' => [],
            ],
            'version' => '1.2.0',
            'last_seen_at' => now(),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
