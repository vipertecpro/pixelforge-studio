<?php

namespace App\Pricing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;

/**
 * The only place that turns tokens into money. Pixelforge's cost is exact; what a
 * user pays is that cost plus the markup, rounded half up to a whole nanodollar
 * (with Pixelforge's current rates the markup is always exact, so nothing rounds).
 */
final class GenerationPricer
{
    public function __construct(private readonly int $markupPercent) {}

    public function estimate(
        PricingSchedule $schedule,
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        int $referenceImageCount,
    ): Estimate {
        $characters = mb_strlen($prompt);
        $textTokens = intdiv($characters + $schedule->charactersPerTextToken - 1, $schedule->charactersPerTextToken);

        // Round outwards, so the range can only be too wide, never too narrow.
        $variance = $schedule->textTokenVariancePercent;
        $textTokensMin = intdiv($textTokens * (100 - $variance), 100);
        $textTokensMax = intdiv($textTokens * (100 + $variance) + 99, 100);

        $imageTokens = $referenceImageCount * $schedule->tokensPerReferenceImage;
        $outputTokens = $schedule->outputTokens[$quality->value][$size->value];
        $reasoning = $schedule->reasoningTokens[$quality->value];

        $providerMin = $schedule->rates->costNano(new TokenUsage($textTokensMin, $imageTokens, $outputTokens, $reasoning['min']));
        $providerMax = $schedule->rates->costNano(new TokenUsage($textTokensMax, $imageTokens, $outputTokens, $reasoning['max']));

        return new Estimate(
            providerMinNano: $providerMin,
            providerMaxNano: $providerMax,
            userMinNano: $this->userCharge($providerMin),
            userMaxNano: $this->userCharge($providerMax),
            textTokensMin: $textTokensMin,
            textTokensMax: $textTokensMax,
            imageTokens: $imageTokens,
            outputTokens: $outputTokens,
            reasoningTokensMin: $reasoning['min'],
            reasoningTokensMax: $reasoning['max'],
        );
    }

    /**
     * What Pixelforge billed for a finished generation, at the rates it was created with.
     */
    public function providerCost(TokenUsage $usage, Rates $rates): int
    {
        return $rates->costNano($usage);
    }

    /**
     * What the user pays for a given Pixelforge cost: cost plus the markup.
     */
    public function userCharge(int $providerCostNano): int
    {
        $percent = 100 + $this->markupPercent;

        return intdiv($providerCostNano * $percent + 50, 100);
    }
}
