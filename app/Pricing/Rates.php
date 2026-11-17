<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * Pixelforge's prices in US dollars per 1M tokens, kept as the decimal strings
 * the API sends. A generation is billed at the rates it was created with, so
 * actual costs always use the rates stored on (or sent with) that generation.
 */
final readonly class Rates
{
    public function __construct(
        public string $inputText,
        public string $inputImage,
        public string $output,
        public string $reasoning,
    ) {
        foreach ($this->toArray() as $key => $rate) {
            self::parse($rate, $key);
        }
    }

    /**
     * @param  array<string, mixed>  $rates
     */
    public static function fromArray(array $rates): self
    {
        $read = function (string $key) use ($rates): string {
            $value = $rates[$key] ?? null;

            if (! is_string($value)) {
                throw new InvalidArgumentException("Rates are missing {$key}.");
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

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputText,
            'input_image_tokens' => $this->inputImage,
            'output_tokens' => $this->output,
            'reasoning_tokens' => $this->reasoning,
        ];
    }

    /**
     * What the given usage costs Pixelforge's customer, in nanodollars.
     */
    public function costNano(TokenUsage $usage): int
    {
        return self::tokensCostNano($usage->inputTextTokens, $this->inputText)
            + self::tokensCostNano($usage->inputImageTokens, $this->inputImage)
            + self::tokensCostNano($usage->outputTokens, $this->output)
            + self::tokensCostNano($usage->reasoningTokens, $this->reasoning);
    }

    /**
     * tokens x (dollars per 1M tokens) is tokens x rate x 1,000 nanodollars.
     * The rate is read as an integer plus a power-of-ten divisor, so the maths
     * stays in integers; it only rounds (half up) for rates with more than
     * three decimals, which Pixelforge does not use.
     */
    private static function tokensCostNano(int $tokens, string $rate): int
    {
        [$digits, $divisor] = self::parse($rate, 'rate');

        return intdiv($tokens * $digits * 1000 + intdiv($divisor, 2), $divisor);
    }

    /**
     * @return array{0: int, 1: int} the rate as digits and a divisor: "12.50" => [1250, 100]
     */
    private static function parse(string $rate, string $name): array
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', $rate, $matches) !== 1) {
            throw new InvalidArgumentException("The {$name} \"{$rate}\" is not a decimal rate.");
        }

        $fraction = $matches[2] ?? '';

        return [(int) ($matches[1].$fraction), 10 ** strlen($fraction)];
    }
}
