<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * Money helpers. Every amount in the app is a whole number of nanodollars
 * (1 USD = 1,000,000,000), which matches Pixelforge's nine-decimal costs exactly
 * and means no floating-point arithmetic ever touches money.
 */
final class Nano
{
    public const PER_DOLLAR = 1_000_000_000;

    /**
     * Parse a decimal USD string such as "0.046125000" into nanodollars.
     */
    public static function fromUsdString(string $usd): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', $usd, $matches) !== 1) {
            throw new InvalidArgumentException("Not a USD amount with at most nine decimals: {$usd}");
        }

        return ((int) $matches[1]) * self::PER_DOLLAR + (int) str_pad($matches[2] ?? '', 9, '0');
    }

    /**
     * The exact decimal string, always nine decimals: 46125000 => "0.046125000".
     */
    public static function toUsdString(int $nano): string
    {
        $absolute = abs($nano);
        $whole = intdiv($absolute, self::PER_DOLLAR);
        $fraction = str_pad((string) ($absolute % self::PER_DOLLAR), 9, '0', STR_PAD_LEFT);

        return ($nano < 0 ? '-' : '').$whole.'.'.$fraction;
    }

    /**
     * A readable, still exact, amount for the interface: trailing zeros are
     * dropped but at least $minimumDecimals digits are kept. 46125000 => "$0.046125".
     */
    public static function format(int $nano, int $minimumDecimals = 4): string
    {
        [$whole, $fraction] = explode('.', self::toUsdString(abs($nano)));
        $fraction = str_pad(rtrim($fraction, '0'), $minimumDecimals, '0');

        return ($nano < 0 ? '-' : '').'$'.$whole.'.'.$fraction;
    }
}
