<?php

namespace App\Pixelforge;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix time>,v1=<hex HMAC-SHA256>",
 * where the HMAC covers "<t>.<raw request body>" and is keyed with the signing
 * secret of the API key that created the generation.
 */
final class WebhookSignature
{
    public function __construct(
        private readonly ?string $secret,
        private readonly int $toleranceSeconds,
    ) {}

    public function isValid(string $rawBody, ?string $header, ?int $now = null): bool
    {
        // Without a secret nothing can be proven, so fail closed.
        if ($this->secret === null || $this->secret === '' || $header === null) {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);

        if (! hash_equals($expected, $signature)) {
            return false;
        }

        return abs(($now ?? time()) - (int) $timestamp) <= $this->toleranceSeconds;
    }

    /**
     * Build a header the same way Pixelforge does (used by the tests).
     */
    public function header(string $rawBody, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, (string) $this->secret);
    }
}
