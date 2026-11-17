<?php

namespace App\Jobs;

use App\Generations\GenerationSettler;
use App\Models\Generation;
use App\Pixelforge\PixelforgeApiException;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one generation to Pixelforge. The generation's own id is the
 * Idempotency-Key, so retrying after a timeout can never create it twice.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /**
     * Pixelforge asks callers to cap retries and back off further each time.
     */
    public int $tries = 8;

    public function __construct(public readonly string $generationId) {}

    public function handle(PixelforgeClient $client, GenerationSettler $settler): void
    {
        $generation = Generation::query()->find($this->generationId);

        // Already known to Pixelforge (a webhook beat us) or already finished.
        if ($generation === null || $generation->pixelforge_id !== null || $generation->status->isFinal()) {
            return;
        }

        try {
            $payload = $client->createGeneration($generation->providerRequest(), $generation->id);
        } catch (PixelforgeApiException $exception) {
            $this->handleFailure($exception, $settler);

            return;
        }

        $settler->apply($payload);
    }

    /**
     * Anything unexpected that outlives every retry fails the generation, so a
     * user is never left looking at a spinner forever.
     */
    public function failed(?Throwable $exception): void
    {
        app(GenerationSettler::class)->failUnsubmitted(
            $this->generationId,
            'internal_error',
            'Something went wrong while sending this request. You were not charged.',
        );
    }

    private function handleFailure(PixelforgeApiException $exception, GenerationSettler $settler): void
    {
        if ($exception->isRetryable()) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->delayFor($exception));

                return;
            }

            // Out of attempts. A 429 or 503 means nothing was created, so the
            // generation can be failed for good. After a timeout we can't know,
            // so it stays queued and generations:sync tries again later.
            if (in_array($exception->status, [429, 503], true)) {
                $settler->failUnsubmitted(
                    $this->generationId,
                    'busy',
                    'Pixelforge is busy right now. You were not charged; please try again.',
                );
            }

            return;
        }

        if (in_array($exception->errorCode, ['invalid_api_key', 'idempotency_key_reused'], true)) {
            Log::error('Pixelforge refused a generation request.', [
                'generation' => $this->generationId,
                'code' => $exception->errorCode,
            ]);
        }

        $settler->failUnsubmitted($this->generationId, $exception->errorCode ?? 'rejected', $this->messageFor($exception));
    }

    /**
     * Honour Retry-After, and back off further after every attempt (2, 4, 8 ...
     * seconds, at most a minute), with a little jitter.
     */
    private function delayFor(PixelforgeApiException $exception): int
    {
        $backoff = min(60, 2 ** $this->attempts());

        return max($exception->retryAfterSeconds ?? 0, $backoff) + random_int(0, 2);
    }

    private function messageFor(PixelforgeApiException $exception): string
    {
        return match ($exception->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. You were not charged.',
            'daily_limit_exceeded' => "Pixelforge's daily generation limit has been reached. Try again tomorrow. You were not charged.",
            'validation_failed' => $exception->getMessage().' You were not charged.',
            'invalid_api_key' => 'Image generation is not set up correctly. You were not charged.',
            default => 'Pixelforge could not accept this request. You were not charged.',
        };
    }
}
