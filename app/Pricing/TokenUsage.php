<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * Token counts for one generation, as reported by Pixelforge once it succeeds.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $usage
     */
    public static function fromArray(array $usage): self
    {
        $read = function (string $key) use ($usage): int {
            $value = $usage[$key] ?? null;

            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException("Usage is missing a valid {$key}.");
            }

            return $value;
        };

        return new self(
            $read('input_text_tokens'),
            $read('input_image_tokens'),
            $read('output_tokens'),
            $read('reasoning_tokens'),
        );
    }
}
