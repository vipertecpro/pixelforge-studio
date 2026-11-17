<?php

namespace App\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\GenerationPricer;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single place a generation changes state, whether the news comes from a
 * webhook, from the response to our create call, or from the polling fallback.
 * Three rules make repeated, late and out-of-order news harmless:
 *
 *  1. The row is locked, so two deliveries can't settle it at the same time.
 *  2. A generation only moves to a higher rank (queued, processing, final).
 *     A late "processing" can never undo "succeeded", and a final state is final.
 *  3. Money is written in the same step that makes the generation final, so it
 *     is written exactly once.
 */
final class GenerationSettler
{
    public function __construct(private readonly GenerationPricer $pricer) {}

    /**
     * Apply a Pixelforge generation object (from a webhook, a poll or the create
     * response). Returns null when it belongs to no generation of ours.
     *
     * @param  array<string, mixed>  $payload
     */
    public function apply(array $payload): ?Generation
    {
        return DB::transaction(function () use ($payload): ?Generation {
            $generation = $this->findAndLock($payload);

            if ($generation === null) {
                return null;
            }

            if ($generation->pixelforge_id === null && is_string($payload['id'] ?? null)) {
                $generation->pixelforge_id = $payload['id'];
            }

            $incoming = GenerationStatus::from((string) ($payload['status'] ?? ''));

            if ($incoming->rank() > $generation->status->rank()) {
                match ($incoming) {
                    GenerationStatus::Processing => $generation->status = GenerationStatus::Processing,
                    GenerationStatus::Succeeded => $this->markSucceeded($generation, $payload),
                    GenerationStatus::Failed => $this->markFailed($generation, $payload),
                    GenerationStatus::Queued => null,
                };
            }

            $generation->save();

            return $generation;
        });
    }

    /**
     * Fail a generation that Pixelforge never accepted (rejected prompt, spent
     * quota, still busy after our retries). Nothing was created on their side, so
     * nothing is billed. Does nothing once Pixelforge knows the generation.
     */
    public function failUnsubmitted(string $generationId, string $code, string $message): void
    {
        DB::transaction(function () use ($generationId, $code, $message): void {
            $generation = Generation::query()->lockForUpdate()->find($generationId);

            if ($generation === null || $generation->pixelforge_id !== null || $generation->status->isFinal()) {
                return;
            }

            $generation->status = GenerationStatus::Failed;
            $generation->error_code = $code;
            $generation->error_message = $message;
            $generation->provider_cost_nano = 0;
            $generation->charged_nano = 0;
            $generation->completed_at = now();
            $generation->save();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findAndLock(array $payload): ?Generation
    {
        $providerId = $payload['id'] ?? null;
        $metadata = $payload['metadata'] ?? null;
        $localId = is_array($metadata) ? ($metadata['generation_id'] ?? null) : null;

        $generation = is_string($providerId)
            ? Generation::query()->where('pixelforge_id', $providerId)->lockForUpdate()->first()
            : null;

        // A webhook can arrive before our create call has returned, so the row
        // does not know its Pixelforge id yet. The metadata we sent finds it.
        if ($generation === null && is_string($localId)) {
            $generation = Generation::query()->lockForUpdate()->find($localId);

            if ($generation !== null && $generation->pixelforge_id !== null && $generation->pixelforge_id !== $providerId) {
                return null;
            }
        }

        return $generation;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markSucceeded(Generation $generation, array $payload): void
    {
        $usage = $payload['usage'] ?? null;
        $rates = $payload['rates'] ?? null;

        if (! is_array($usage) || ! is_array($rates)) {
            throw new InvalidArgumentException('A succeeded generation must carry usage and rates.');
        }

        // Billed at the rates this generation was created with, never today's.
        $providerCost = $this->pricer->providerCost(TokenUsage::fromArray($usage), Rates::fromArray($rates));

        $generation->status = GenerationStatus::Succeeded;
        $generation->usage = $usage;
        $generation->rates = $rates;
        $generation->provider_cost_nano = $providerCost;
        $generation->charged_nano = $this->pricer->userCharge($providerCost);
        $generation->image_url = is_string($payload['image_url'] ?? null) ? $payload['image_url'] : null;
        $generation->image_url_expires_at = $this->time($payload['image_url_expires_at'] ?? null);
        $generation->error_code = null;
        $generation->error_message = null;
        $generation->completed_at = $this->time($payload['completed_at'] ?? null) ?? now();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markFailed(Generation $generation, array $payload): void
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        $generation->status = GenerationStatus::Failed;
        $generation->error_code = is_string($error['code'] ?? null) ? $error['code'] : 'generation_failed';
        $generation->error_message = is_string($error['message'] ?? null)
            ? $error['message']
            : 'The image could not be generated. You were not charged.';
        $generation->provider_cost_nano = 0;
        $generation->charged_nano = 0;
        $generation->completed_at = $this->time($payload['completed_at'] ?? null) ?? now();
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
