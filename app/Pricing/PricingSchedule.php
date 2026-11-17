<?php

namespace App\Pricing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use InvalidArgumentException;

/**
 * The public GET /v1/pricing table: the current rates plus how token counts are
 * worked out. Estimates read from this instead of hard-coding any number.
 */
final readonly class PricingSchedule
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  by quality, then size
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  by quality
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public int $textTokenVariancePercent,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {
        if ($charactersPerTextToken < 1) {
            throw new InvalidArgumentException('characters_per_text_token must be at least 1.');
        }
    }

    /**
     * @param  array<string, mixed>  $pricing  the decoded /v1/pricing response
     */
    public static function fromArray(array $pricing): self
    {
        $rates = $pricing['rates'] ?? null;
        $tokens = $pricing['tokens'] ?? null;

        if (! is_array($rates) || ! is_array($tokens)) {
            throw new InvalidArgumentException('The pricing response has no rates or tokens.');
        }

        $outputTokens = [];
        $reasoningTokens = [];

        foreach (ImageQuality::cases() as $quality) {
            foreach (ImageSize::cases() as $size) {
                $count = $tokens['output'][$quality->value][$size->value] ?? null;

                if (! is_int($count)) {
                    throw new InvalidArgumentException("No output token count for {$quality->value} {$size->value}.");
                }

                $outputTokens[$quality->value][$size->value] = $count;
            }

            $range = $tokens['reasoning'][$quality->value] ?? null;

            if (! is_array($range) || ! is_int($range['min'] ?? null) || ! is_int($range['max'] ?? null)) {
                throw new InvalidArgumentException("No reasoning token range for {$quality->value}.");
            }

            $reasoningTokens[$quality->value] = ['min' => $range['min'], 'max' => $range['max']];
        }

        $variance = $tokens['text_token_variance'] ?? null;
        $charactersPerToken = $tokens['characters_per_text_token'] ?? null;
        $perReferenceImage = $tokens['per_reference_image'] ?? null;

        if (! is_numeric($variance) || ! is_int($charactersPerToken) || ! is_int($perReferenceImage)) {
            throw new InvalidArgumentException('The pricing response is missing token settings.');
        }

        return new self(
            Rates::fromArray($rates),
            $charactersPerToken,
            (int) round(((float) $variance) * 100),
            $perReferenceImage,
            $outputTokens,
            $reasoningTokens,
        );
    }
}
