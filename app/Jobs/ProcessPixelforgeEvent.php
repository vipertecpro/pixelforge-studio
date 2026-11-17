<?php

namespace App\Jobs;

use App\Generations\GenerationSettler;
use App\Models\PixelforgeEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Applies one stored webhook event. Safe to run twice: an event that is already
 * processed is skipped, and the settler ignores anything that would move a
 * generation backwards.
 */
class ProcessPixelforgeEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly int $eventId) {}

    public function handle(GenerationSettler $settler): void
    {
        DB::transaction(function () use ($settler): void {
            $event = PixelforgeEvent::query()->lockForUpdate()->find($this->eventId);

            if ($event === null || $event->processed_at !== null) {
                return;
            }

            $generation = $event->payload['data']['object'] ?? null;

            if (str_starts_with($event->type, 'generation.') && is_array($generation)) {
                $settler->apply($generation);
            }

            $event->processed_at = now();
            $event->save();
        });
    }
}
