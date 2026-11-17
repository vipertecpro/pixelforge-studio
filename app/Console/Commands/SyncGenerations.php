<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Generations\GenerationSettler;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeApiException;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Webhooks are not guaranteed to arrive, so this runs every minute as a safety
 * net: it polls Pixelforge for generations that have gone quiet, and re-sends
 * (safely, with the same idempotency key) any that were never accepted.
 */
#[Signature('generations:sync')]
#[Description('Poll Pixelforge for generations whose webhook never arrived, and re-send unaccepted ones')]
class SyncGenerations extends Command
{
    private const GIVE_UP_AFTER_SECONDS = 3600;

    public function handle(PixelforgeClient $client, GenerationSettler $settler): int
    {
        $polled = $this->pollQuietGenerations($client, $settler);
        $resent = $this->resendUnacceptedGenerations($settler);

        $this->components->info("Polled {$polled}, re-sent {$resent}.");

        return self::SUCCESS;
    }

    private function pollQuietGenerations(PixelforgeClient $client, GenerationSettler $settler): int
    {
        $generations = Generation::query()
            ->whereIn('status', GenerationStatus::active())
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subSeconds((int) config('pixelforge.sync_after_seconds')))
            ->oldest('updated_at')
            ->limit(30)
            ->get();

        $polled = 0;

        foreach ($generations as $generation) {
            try {
                $settler->apply($client->getGeneration((string) $generation->pixelforge_id));
                $polled++;
            } catch (PixelforgeApiException $exception) {
                $this->components->warn("Could not poll {$generation->pixelforge_id}: {$exception->getMessage()}");

                if ($exception->status === 429) {
                    break;
                }
            }
        }

        return $polled;
    }

    private function resendUnacceptedGenerations(GenerationSettler $settler): int
    {
        $generations = Generation::query()
            ->where('status', GenerationStatus::Queued)
            ->whereNull('pixelforge_id')
            ->where('updated_at', '<', now()->subSeconds((int) config('pixelforge.resubmit_after_seconds')))
            ->oldest('updated_at')
            ->limit(30)
            ->get();

        foreach ($generations as $generation) {
            if ($generation->created_at !== null && $generation->created_at->lt(now()->subSeconds(self::GIVE_UP_AFTER_SECONDS))) {
                $settler->failUnsubmitted(
                    $generation->id,
                    'not_accepted',
                    'Pixelforge could not be reached for this request. You were not charged; please try again.',
                );

                continue;
            }

            $generation->touch();
            SubmitGeneration::dispatch($generation->id);
        }

        return $generations->count();
    }
}
