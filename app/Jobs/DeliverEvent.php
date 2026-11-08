<?php

namespace App\Jobs;

use App\Models\AppEvent;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one event to all matching notification channels.
 */
class DeliverEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $eventId) {}

    public function handle(Notifier $notifier): void
    {
        $event = AppEvent::query()->find($this->eventId);
        if ($event !== null) {
            $notifier->deliver($event);
        }
    }
}
