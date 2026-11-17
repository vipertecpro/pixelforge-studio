<?php

namespace App\Pixelforge;

use App\Pricing\PricingSchedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Thin wrapper around the Pixelforge REST API. Every failure becomes a
 * PixelforgeApiException so callers branch on one type, not on HTTP details.
 */
final class PixelforgeClient
{
    private const PRICING_CACHE_KEY = 'pixelforge.pricing';

    private const PRICING_STALE_KEY = 'pixelforge.pricing.stale';

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $pricingCacheSeconds = 60,
    ) {}

    /**
     * The current price list. Cached briefly; if Pixelforge is down the last
     * copy we saw is used instead of blocking users.
     */
    public function pricing(): PricingSchedule
    {
        $cached = Cache::get(self::PRICING_CACHE_KEY);

        if (is_array($cached)) {
            return PricingSchedule::fromArray($cached);
        }

        try {
            $fresh = $this->send(fn () => $this->http(authenticated: false)->get('/pricing'));
            $schedule = PricingSchedule::fromArray($fresh);
        } catch (PixelforgeApiException|InvalidArgumentException $exception) {
            $stale = Cache::get(self::PRICING_STALE_KEY);

            if (is_array($stale)) {
                return PricingSchedule::fromArray($stale);
            }

            throw $exception instanceof PixelforgeApiException
                ? $exception
                : new PixelforgeApiException('Pixelforge sent a pricing table we could not read.', previous: $exception);
        }

        Cache::put(self::PRICING_CACHE_KEY, $fresh, now()->addSeconds($this->pricingCacheSeconds));
        Cache::forever(self::PRICING_STALE_KEY, $fresh);

        return $schedule;
    }

    /**
     * Create a generation. The Idempotency-Key makes a retry safe: the same key
     * with the same body returns the same generation instead of a second one.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $body, string $idempotencyKey): array
    {
        return $this->send(
            fn () => $this->http()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('/generations', $body)
        );
    }

    /**
     * @return array<string, mixed> the generation object
     */
    public function getGeneration(string $pixelforgeId): array
    {
        return $this->send(fn () => $this->http()->get('/generations/'.rawurlencode($pixelforgeId)));
    }

    /**
     * What Pixelforge billed this API key per UTC day (the source of truth for provider costs).
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->http()->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate]));
    }

    private function http(bool $authenticated = true): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)->acceptJson()->asJson()->connectTimeout(5)->timeout(15);

        if (! $authenticated) {
            return $request;
        }

        if ($this->apiKey === null || $this->apiKey === '') {
            throw new PixelforgeApiException('No Pixelforge API key is configured.', 401, 'invalid_api_key');
        }

        return $request->withToken($this->apiKey);
    }

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>
     */
    private function send(callable $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new PixelforgeApiException('Could not reach Pixelforge.', previous: $exception);
        }

        if ($response->successful()) {
            $json = $response->json();

            if (! is_array($json)) {
                throw new PixelforgeApiException('Pixelforge sent a response that was not JSON.', $response->status());
            }

            return $json;
        }

        $retryAfter = $response->header('Retry-After');

        throw new PixelforgeApiException(
            (string) ($response->json('error.message') ?? 'Pixelforge answered with HTTP '.$response->status().'.'),
            $response->status(),
            is_string($response->json('error.code')) ? $response->json('error.code') : null,
            is_numeric($retryAfter) ? min((int) $retryAfter, 120) : null,
        );
    }
}
