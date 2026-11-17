<?php

namespace App\Pricing;

/**
 * What a generation is expected to cost before it runs. Only two things are
 * unknown up front, the real prompt token count (within a few percent of the
 * estimate) and the reasoning tokens (anywhere in a range set by quality), so
 * the answer is a range. The real price never falls outside it.
 */
final readonly class Estimate
{
    public function __construct(
        public int $providerMinNano,
        public int $providerMaxNano,
        public int $userMinNano,
        public int $userMaxNano,
        public int $textTokensMin,
        public int $textTokensMax,
        public int $imageTokens,
        public int $outputTokens,
        public int $reasoningTokensMin,
        public int $reasoningTokensMax,
    ) {}
}
