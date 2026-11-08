<?php

namespace App\Jobs;

use App\Services\Automation\Linker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reacts to deployment events of ProxApps: link the new app with proxy and monitoring,
 * or remove what was created for it. Runs in the queue (it calls several apps).
 */
class LinkDeployment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public string $sourceApp, public string $type, public array $data) {}

    public function handle(Linker $linker): void
    {
        match ($this->type) {
            'deployment.succeeded' => $linker->link($this->sourceApp, $this->data),
            'deployment.removed' => $linker->unlink($this->sourceApp, (int) ($this->data['deploymentId'] ?? 0)),
            default => null,
        };
    }
}
